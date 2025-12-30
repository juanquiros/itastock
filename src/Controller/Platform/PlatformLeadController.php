<?php

namespace App\Controller\Platform;

use App\Entity\Business;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\LeadRepository;
use App\Repository\PlanRepository;
use App\Repository\UserRepository;
use App\Service\SubscriptionNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
#[Route('/platform/leads')]
class PlatformLeadController extends AbstractController
{
    #[Route('', name: 'platform_leads_index', methods: ['GET', 'POST'])]
    public function index(Request $request, LeadRepository $leadRepository, EntityManagerInterface $entityManager): Response
    {
        $email = $request->query->get('email');
        $from = $request->query->get('from');
        $to = $request->query->get('to');
        $archive = $request->request->get('archive');

        if ($archive) {
            $lead = $leadRepository->find($archive);
            if ($lead) {
                $lead->setIsArchived(true);
                $entityManager->flush();
                $this->addFlash('success', 'Lead archivado.');
            }

            return $this->redirectToRoute('platform_leads_index', $request->query->all());
        }

        $qb = $leadRepository->createQueryBuilder('l')->orderBy('l.createdAt', 'DESC');
        if ($email) {
            $qb->andWhere('l.email LIKE :email')->setParameter('email', '%'.$email.'%');
        }
        if ($from) {
            $qb->andWhere('l.createdAt >= :from')->setParameter('from', new \DateTimeImmutable($from));
        }
        if ($to) {
            $qb->andWhere('l.createdAt <= :to')->setParameter('to', new \DateTimeImmutable($to.' 23:59:59'));
        }
        $leads = $qb->getQuery()->getResult();

        return $this->render('platform/leads/index.html.twig', [
            'leads' => $leads,
            'filters' => ['email' => $email, 'from' => $from, 'to' => $to],
        ]);
    }

    #[Route('/{id}', name: 'platform_leads_show', methods: ['GET'])]
    public function show(int $id, LeadRepository $leadRepository): Response
    {
        $lead = $leadRepository->find($id);
        if (!$lead) {
            throw $this->createNotFoundException();
        }

        return $this->render('platform/leads/show.html.twig', [
            'lead' => $lead,
        ]);
    }

    #[Route('/{id}/create-demo', name: 'platform_leads_create_demo', methods: ['POST'])]
    public function createDemo(
        int $id,
        LeadRepository $leadRepository,
        UserRepository $userRepository,
        PlanRepository $planRepository,
        EntityManagerInterface $entityManager,
        SubscriptionNotificationService $subscriptionNotificationService,
        UserPasswordHasherInterface $passwordHasher,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator,
    ): Response {
        $lead = $leadRepository->find($id);
        if (!$lead || $lead->getSource() !== 'demo' || $lead->isArchived()) {
            $this->addFlash('danger', 'No se puede crear la demo para este lead.');

            return $this->redirectToRoute('platform_leads_index');
        }

        $email = mb_strtolower((string) $lead->getEmail());
        $existingUser = $userRepository->findOneBy(['email' => $email]);
        if ($existingUser) {
            $lead->setIsArchived(true);
            $entityManager->flush();
            $this->addFlash('danger', 'Ya existe un usuario con este correo.');

            return $this->redirectToRoute('platform_leads_show', ['id' => $lead->getId()]);
        }

        $plan = $this->resolveDemoPlan($planRepository);
        if (!$plan) {
            $this->addFlash('danger', 'No hay planes activos para asignar la demo.');

            return $this->redirectToRoute('platform_leads_show', ['id' => $lead->getId()]);
        }

        $business = new Business();
        $business->setName($this->resolveBusinessName($lead, $email));

        $user = new User();
        $user->setEmail($email);
        $user->setFullName($lead->getName() ?: $business->getName());
        $user->setRoles(['ROLE_ADMIN']);
        $user->setBusiness($business);
        $temporaryPassword = bin2hex(random_bytes(16));
        $user->setPassword($passwordHasher->hashPassword($user, $temporaryPassword));

        $token = bin2hex(random_bytes(32));
        $user->setResetToken($token);
        $user->setResetRequestedAt(new \DateTimeImmutable());

        $subscription = new Subscription();
        $subscription->setBusiness($business);
        $subscription->setPlan($plan);
        $subscription->setStatus(Subscription::STATUS_TRIAL);
        $subscription->setStartAt(new \DateTimeImmutable());
        $subscription->setTrialEndsAt(null);

        $business->setSubscription($subscription);
        $lead->setIsArchived(true);

        $entityManager->persist($business);
        $entityManager->persist($user);
        $entityManager->persist($subscription);
        $entityManager->flush();

        $subscriptionNotificationService->onDemoEnabled($subscription);

        $resetUrl = $urlGenerator->generate('app_password_reset', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

        $emailMessage = (new TemplatedEmail())
            ->from(new Address('no-reply@itastock.test', 'ItaStock'))
            ->to($email)
            ->subject('Tu demo de ItaStock: configurá tu contraseña')
            ->htmlTemplate('emails/demo_set_password.html.twig')
            ->context([
                'resetUrl' => $resetUrl,
                'user' => $user,
                'business' => $business,
            ]);

        $mailer->send($emailMessage);

        $this->addFlash('success', 'Demo creada y email enviado.');

        return $this->redirectToRoute('platform_leads_show', ['id' => $lead->getId()]);
    }

    private function resolveDemoPlan(PlanRepository $planRepository): ?\App\Entity\Plan
    {
        $plan = $planRepository->findOneBy(['code' => 'demo']);
        if ($plan) {
            return $plan;
        }

        $plan = $planRepository->findOneBy(['code' => 'trial']);
        if ($plan) {
            return $plan;
        }

        $activePlans = $planRepository->findActiveOrdered();

        // Fall back to the cheapest active plan (first in ordered list) when no demo/trial plan exists.
        return $activePlans[0] ?? null;
    }

    private function resolveBusinessName(\App\Entity\Lead $lead, string $email): string
    {
        $businessName = trim((string) $lead->getBusinessName());
        if ($businessName !== '') {
            return $businessName;
        }

        $name = trim((string) $lead->getName());
        if ($name !== '') {
            return $name;
        }

        $prefix = strstr($email, '@', true);

        return $prefix ? ucfirst($prefix) : 'Demo';
    }
}
