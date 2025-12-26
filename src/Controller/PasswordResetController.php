<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RequestPasswordResetType;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use App\Service\TrialSubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mailer\MailerInterface;

#[Route('/password', name: 'app_password_')]
class PasswordResetController extends AbstractController
{
    #[Route('/forgot', name: 'request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator,
    ): Response {
        $form = $this->createForm(RequestPasswordResetType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower($form->get('email')->getData());
            $user = $userRepository->findOneBy(['email' => $email]);

            if ($user instanceof User) {
                $token = bin2hex(random_bytes(32));
                $user->setResetToken($token);
                $user->setResetRequestedAt(new \DateTimeImmutable());
                $entityManager->flush();

                $resetUrl = $urlGenerator->generate('app_password_reset', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

                $emailMessage = (new TemplatedEmail())
                    ->from(new Address('no-reply@itastock.test', 'ItaStock'))
                    ->to($user->getEmail())
                    ->subject('Restablece tu contraseña')
                    ->htmlTemplate('emails/password_reset.html.twig')
                    ->context([
                        'resetUrl' => $resetUrl,
                        'user' => $user,
                    ]);

                $mailer->send($emailMessage);
            }

            $this->addFlash('info', 'Si el correo existe, recibirás un enlace para restablecer la contraseña.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/password_request.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/reset/{token}', name: 'reset', methods: ['GET', 'POST'])]
    public function reset(
        string $token,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TrialSubscriptionService $trialSubscriptionService,
    ): Response {
        $user = $userRepository->findOneByResetToken($token);

        if (!$user instanceof User || !$this->isValidToken($user)) {
            $this->addFlash('danger', 'El enlace de restablecimiento no es válido o ha expirado.');

            return $this->redirectToRoute('app_password_request');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hashedPassword = $passwordHasher->hashPassword($user, $form->get('plainPassword')->getData());
            $user->setPassword($hashedPassword);
            $user->setResetToken(null);
            $user->setResetRequestedAt(null);
            $trialSubscriptionService->startTrialIfNeeded($user);

            $entityManager->flush();

            $this->addFlash('success', 'Contraseña actualizada. Ahora puedes iniciar sesión.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/password_reset.html.twig', [
            'form' => $form,
        ]);
    }

    private function isValidToken(User $user): bool
    {
        $requestedAt = $user->getResetRequestedAt();

        if (null === $requestedAt) {
            return false;
        }

        $expiresAt = $requestedAt->modify('+1 hour');

        return $expiresAt > new \DateTimeImmutable();
    }
}
