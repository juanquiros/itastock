<?php

namespace App\Controller;

use App\Form\EmailPreferenceType;
use App\Repository\EmailPreferenceRepository;
use App\Security\BusinessContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
class EmailPreferenceController extends AbstractController
{
    public function __construct(private readonly BusinessContext $businessContext)
    {
    }

    #[Route('/app/settings/emails', name: 'app_email_preferences', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EmailPreferenceRepository $emailPreferenceRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $business = $this->businessContext->requireCurrentBusiness();

        $preference = $emailPreferenceRepository->getBusinessPreference($business);

        if ($preference->getId() === null) {
            $entityManager->persist($preference);
            $entityManager->flush();
        }

        $form = $this->createForm(EmailPreferenceType::class, $preference, [
            'timezone' => $preference->getTimezone(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Preferencias de email actualizadas.');

            return $this->redirectToRoute('app_email_preferences');
        }

        return $this->render('settings/email_preferences.html.twig', [
            'form' => $form,
        ]);
    }
}
