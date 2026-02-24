<?php

namespace App\Controller;

use App\Entity\Business;
use App\Form\BusinessQuotationSettingsType;
use App\Security\BusinessContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/settings', name: 'app_settings_')]
class QuotationSettingsController extends AbstractController
{
    public function __construct(private readonly BusinessContext $businessContext)
    {
    }

    #[Route('/quotation', name: 'quotation', methods: ['GET', 'POST'])]
    public function quotation(Request $request, EntityManagerInterface $entityManager): Response
    {
        $business = $this->requireBusinessContext();
        $form = $this->createForm(BusinessQuotationSettingsType::class, $business);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Configuración del presupuesto actualizada.');

            return $this->redirectToRoute('app_settings_quotation');
        }

        return $this->render('settings/quotation.html.twig', [
            'form' => $form->createView(),
            'business' => $business,
        ]);
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
