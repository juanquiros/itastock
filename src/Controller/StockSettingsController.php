<?php

namespace App\Controller;

use App\Entity\Business;
use App\Form\BusinessStockSettingsType;
use App\Security\BusinessContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/settings', name: 'app_settings_')]
class StockSettingsController extends AbstractController
{
    public function __construct(private readonly BusinessContext $businessContext)
    {
    }

    #[Route('/stock', name: 'stock', methods: ['GET', 'POST'])]
    public function stock(Request $request, EntityManagerInterface $entityManager): Response
    {
        $business = $this->requireBusinessContext();
        $form = $this->createForm(BusinessStockSettingsType::class, $business);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Configuración de stock actualizada.');

            return $this->redirectToRoute('app_settings_stock');
        }

        return $this->render('settings/stock.html.twig', [
            'form' => $form->createView(),
            'business' => $business,
        ]);
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
