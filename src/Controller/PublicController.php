<?php

namespace App\Controller;

use App\Entity\Lead;
use App\Entity\Plan;
use App\Entity\PublicPage;
use App\Form\LeadType;
use App\Repository\PlanRepository;
use App\Repository\PublicPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PublicController extends AbstractController
{
    public function __construct(
        private readonly PublicPageRepository $publicPageRepository,
        private readonly PlanRepository $planRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/', name: 'public_home', methods: ['GET'])]
    public function home(): Response
    {
        $page = $this->publicPageRepository->findPublishedBySlug('home');

        return $this->renderPublicPage($page, 'Home');
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

        if ($form->isSubmitted() && $form->isValid()) {
            $lead->setSource($request->query->get('source', 'web'));
            $this->entityManager->persist($lead);
            $this->entityManager->flush();

            $this->addFlash('success', '¡Gracias! Registramos tu consulta y te contactaremos pronto.');

            return $this->redirectToRoute('public_contact');
        }

        return $this->render('public/contact.html.twig', [
            'contactForm' => $form->createView(),
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
}
