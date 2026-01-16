<?php

namespace App\Controller;

use App\Entity\Lead;
use App\Entity\Plan;
use App\Entity\PublicPage;
use App\Form\LeadDemoType;
use App\Form\LeadType;
use App\Repository\LeadRepository;
use App\Repository\PlanRepository;
use App\Repository\PlatformSettingsRepository;
use App\Repository\PublicPageRepository;
use App\Service\EmailSender;
use App\Service\PlatformNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PublicController extends AbstractController
{
    public function __construct(
        private readonly PublicPageRepository $publicPageRepository,
        private readonly PlanRepository $planRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LeadRepository $leadRepository,
        private readonly PlatformSettingsRepository $platformSettingsRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly string $recaptchaSiteKey,
        private readonly string $recaptchaSecretKey,
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
                'whatsappLink' => $this->resolveWhatsappLink(),
            ]);
            $response->setStatusCode(Response::HTTP_NOT_FOUND);

            return $response;
        }

        $lead = new Lead();
        $form = $this->createForm(LeadDemoType::class, $lead);
        $form->handleRequest($request);
        $demoSubmitted = false;

        $captchaValid = $this->validateRecaptcha($request, $form);

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
        }

        return $this->render('public/home.html.twig', [
            'page' => $page,
            'demoForm' => $form->createView(),
            'demoSubmitted' => $demoSubmitted,
            'recaptchaSiteKey' => $this->recaptchaSiteKey,
            'whatsappLink' => $this->resolveWhatsappLink(),
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
            'whatsappLink' => $this->resolveWhatsappLink(),
        ]);
    }

    #[Route('/contact', name: 'public_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request): Response
    {
        $lead = new Lead();
        $form = $this->createForm(LeadType::class, $lead);
        $form->handleRequest($request);
        $captchaValid = $this->validateRecaptcha($request, $form);

        if ($form->isSubmitted() && $form->isValid() && $captchaValid) {
            $lead->setSource($request->query->get('source', 'web'));
            $this->entityManager->persist($lead);
            $this->entityManager->flush();

            $this->addFlash('success', '¡Gracias! Registramos tu consulta y te contactaremos pronto.');

            return $this->redirectToRoute('public_contact');
        }

        return $this->render('public/contact.html.twig', [
            'contactForm' => $form->createView(),
            'recaptchaSiteKey' => $this->recaptchaSiteKey,
            'whatsappLink' => $this->resolveWhatsappLink(),
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
                'whatsappLink' => $this->resolveWhatsappLink(),
            ]);
            $response->setStatusCode(Response::HTTP_NOT_FOUND);

            return $response;
        }

        return $this->render('public/page.html.twig', [
            'page' => $page,
            'whatsappLink' => $this->resolveWhatsappLink(),
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

    private function resolveWhatsappLink(): ?string
    {
        $settings = $this->platformSettingsRepository->findOneBy([]);
        $raw = trim((string) ($settings?->getWhatsappLink() ?? ''));
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        if (str_starts_with($raw, 'wa.me/')) {
            return 'https://'.$raw;
        }

        $digits = preg_replace('/\\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }

        return 'https://wa.me/'.$digits;
    }

    private function validateRecaptcha(Request $request, FormInterface $form): bool
    {
        if (!$form->isSubmitted()) {
            return true;
        }

        if ($this->recaptchaSecretKey === '') {
            return true;
        }

        $token = (string) $request->request->get('g-recaptcha-response', '');
        if ($token === '') {
            $form->addError(new FormError('Por favor completá el reCAPTCHA.'));

            return false;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://www.google.com/recaptcha/api/siteverify', [
                'body' => [
                    'secret' => $this->recaptchaSecretKey,
                    'response' => $token,
                    'remoteip' => $request->getClientIp(),
                ],
            ]);
            $payload = $response->toArray(false);
        } catch (\Throwable $exception) {
            $form->addError(new FormError('No se pudo validar el reCAPTCHA. Intentá nuevamente.'));

            return false;
        }

        if (!($payload['success'] ?? false)) {
            $form->addError(new FormError('Validación de reCAPTCHA fallida. Intentá nuevamente.'));

            return false;
        }

        return true;
    }
}
