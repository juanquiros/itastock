<?php

namespace App\Controller;

use App\Entity\Lead;
use App\Entity\Plan;
use App\Entity\PublicPage;
use App\Form\LeadDemoType;
use App\Form\LeadType;
use App\Repository\LeadRepository;
use App\Repository\PlanRepository;
use App\Repository\PublicPageRepository;
use App\Service\EmailSender;
use App\Service\PlatformNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PublicController extends AbstractController
{
    public function __construct(
        private readonly PublicPageRepository $publicPageRepository,
        private readonly PlanRepository $planRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LeadRepository $leadRepository,
    ) {
    }

    #[Route('/', name: 'public_home', methods: ['GET', 'POST'])]
    public function home(Request $request, PlatformNotificationService $platformNotificationService, EmailSender $emailSender): Response
    {
        $page = $this->publicPageRepository->findPublishedBySlug('home');
        if ($page === null) {
            $response = $this->render('public/page_placeholder.html.twig', [
                'title' => 'Home',
                'message' => 'En construcción',
            ]);
            $response->setStatusCode(Response::HTTP_NOT_FOUND);

            return $response;
        }

        $lead = new Lead();
        $form = $this->createForm(LeadDemoType::class, $lead);
        $form->handleRequest($request);
        $demoSubmitted = false;
        $captcha = $this->getCaptchaChallenge($request, 'demo_captcha');

        $captchaValid = true;
        if ($form->isSubmitted()) {
            $captchaValid = $this->validateCaptchaAnswer($form, $captcha['answer']);
        }

        if ($form->isSubmitted() && $form->isValid() && $captchaValid) {
            $email = mb_strtolower((string) $lead->getEmail());
            $existing = $this->leadRepository->findOneBy([
                'email' => $email,
                'source' => 'demo',
                'isArchived' => false,
            ]);

            if ($existing) {
                $this->addFlash('info', 'Ya recibimos tu solicitud. Revisá tu correo.');
            } else {
                $lead->setEmail($email);
                $lead->setSource('demo');
                $lead->setMessage('Solicitud de demo desde la landing.');
                $lead->setCreatedAt(new \DateTimeImmutable());
                $lead->setName($this->resolveLeadName($lead, $email));
                $this->entityManager->persist($lead);
                $this->entityManager->flush();
                $emailSender->sendTemplatedEmail(
                    'DEMO_REQUEST_RECEIVED',
                    $lead->getEmail() ?? $email,
                    'PUBLIC',
                    'Recibimos tu solicitud de demo',
                    'emails/demo/demo_request_received.html.twig',
                    [
                        'name' => $lead->getName(),
                        'businessName' => $lead->getBusinessName(),
                        'ctaUrl' => $this->generateUrl('public_contact', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
                    ],
                    null,
                    null,
                    null,
                    null,
                );
                $platformNotificationService->notifyDemoRequest($lead);
                $this->addFlash('success', '¡Gracias! Recibimos tu solicitud de demo.');
                $demoSubmitted = true;
            }

            $this->resetCaptchaChallenge($request, 'demo_captcha');
        }

        return $this->render('public/home.html.twig', [
            'page' => $page,
            'demoForm' => $form->createView(),
            'demoSubmitted' => $demoSubmitted,
            'demoCaptcha' => $captcha,
        ]);
    }

    #[Route('/features', name: 'public_features', methods: ['GET'])]
    public function features(): Response
    {
        $page = $this->publicPageRepository->findPublishedBySlug('features');

        return $this->renderPublicPage($page, 'Funcionalidades');
    }

    #[Route('/pricing', name: 'public_pricing', methods: ['GET'])]
    public function pricing(): Response
    {
        $plans = $this->planRepository->findActiveOrdered();

        $plansData = array_map(static function (Plan $plan): array {
            $rawFeatures = $plan->getFeaturesJson();
            $decoded = $rawFeatures ? json_decode($rawFeatures, true) : null;

            return [
                'plan' => $plan,
                'features' => is_array($decoded) ? $decoded : null,
                'featuresRaw' => $rawFeatures,
            ];
        }, $plans);

        return $this->render('public/pricing.html.twig', [
            'plansData' => $plansData,
        ]);
    }

    #[Route('/contact', name: 'public_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request): Response
    {
        $lead = new Lead();
        $form = $this->createForm(LeadType::class, $lead);
        $form->handleRequest($request);
        $captcha = $this->getCaptchaChallenge($request, 'contact_captcha');

        $captchaValid = true;
        if ($form->isSubmitted()) {
            $captchaValid = $this->validateCaptchaAnswer($form, $captcha['answer']);
        }

        if ($form->isSubmitted() && $form->isValid() && $captchaValid) {
            $lead->setSource($request->query->get('source', 'web'));
            $this->entityManager->persist($lead);
            $this->entityManager->flush();

            $this->addFlash('success', '¡Gracias! Registramos tu consulta y te contactaremos pronto.');
            $this->resetCaptchaChallenge($request, 'contact_captcha');

            return $this->redirectToRoute('public_contact');
        }

        return $this->render('public/contact.html.twig', [
            'contactForm' => $form->createView(),
            'contactCaptcha' => $captcha,
        ]);
    }

    #[Route('/terms', name: 'public_terms', methods: ['GET'])]
    public function terms(): Response
    {
        $page = $this->publicPageRepository->findPublishedBySlug('terms');

        return $this->renderPublicPage($page, 'Términos y condiciones', placeholderMessage: 'Estamos preparando estos términos.');
    }

    #[Route('/privacy', name: 'public_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        $page = $this->publicPageRepository->findPublishedBySlug('privacy');

        return $this->renderPublicPage($page, 'Política de privacidad', placeholderMessage: 'Pronto publicaremos nuestra política de privacidad.');
    }

    #[Route('/p/{slug}', name: 'public_page', methods: ['GET'])]
    public function page(string $slug): Response
    {
        $page = $this->publicPageRepository->findPublishedBySlug($slug);

        return $this->renderPublicPage($page, ucfirst($slug));
    }

    private function renderPublicPage(?PublicPage $page, string $fallbackTitle, string $placeholderMessage = 'En construcción'): Response
    {
        if ($page === null) {
            $response = $this->render('public/page_placeholder.html.twig', [
                'title' => $fallbackTitle,
                'message' => $placeholderMessage,
            ]);
            $response->setStatusCode(Response::HTTP_NOT_FOUND);

            return $response;
        }

        return $this->render('public/page.html.twig', [
            'page' => $page,
        ]);
    }

    private function resolveLeadName(Lead $lead, string $email): string
    {
        $name = trim((string) $lead->getName());
        if ($name !== '') {
            return $name;
        }

        $prefix = strstr($email, '@', true);

        return $prefix ? ucfirst($prefix) : 'Demo';
    }

    /**
     * @return array{a:int, b:int, answer:int, label:string}
     */
    private function getCaptchaChallenge(Request $request, string $key): array
    {
        $session = $request->getSession();
        $stored = $session?->get($key);

        if (!is_array($stored) || !isset($stored['a'], $stored['b'], $stored['answer'])) {
            $a = random_int(2, 9);
            $b = random_int(2, 9);
            $stored = [
                'a' => $a,
                'b' => $b,
                'answer' => $a + $b,
                'label' => sprintf('%d + %d', $a, $b),
            ];
            $session?->set($key, $stored);
        } else {
            $stored['label'] = sprintf('%d + %d', $stored['a'], $stored['b']);
        }

        return $stored;
    }

    private function resetCaptchaChallenge(Request $request, string $key): void
    {
        $request->getSession()?->remove($key);
    }

    private function validateCaptchaAnswer($form, int $expectedAnswer): bool
    {
        if (!$form->has('captchaAnswer')) {
            return true;
        }

        $value = $form->get('captchaAnswer')->getData();
        if ($value === null || (string) $value === '') {
            return false;
        }

        if ((int) $value !== $expectedAnswer) {
            $form->get('captchaAnswer')->addError(new FormError('Respuesta incorrecta. Intentá nuevamente.'));

            return false;
        }

        return true;
    }
}
