<?php

namespace App\Controller;

use App\Entity\Business;
use App\Form\BusinessLabelSettingsType;
use App\Security\BusinessContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/settings', name: 'app_settings_')]
class LabelSettingsController extends AbstractController
{
    public function __construct(private readonly BusinessContext $businessContext)
    {
    }

    #[Route('/labels', name: 'labels', methods: ['GET', 'POST'])]
    public function labels(Request $request, EntityManagerInterface $entityManager): Response
    {
        $business = $this->requireBusinessContext();
        $form = $this->createForm(BusinessLabelSettingsType::class, [
            'labelImagePath' => $business->getLabelImagePath(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $formName = $form->getName();
            $rawData = $request->request->all($formName);
            $labelImagePath = $rawData['labelImagePath'] ?? $data['labelImagePath'] ?? null;

            $business->setLabelImagePath($labelImagePath ?: null);
            $entityManager->flush();

            $this->addFlash('success', 'Imagen de etiqueta actualizada.');

            return $this->redirectToRoute('app_settings_labels');
        }

        return $this->render('settings/labels.html.twig', [
            'form' => $form->createView(),
            'business' => $business,
        ]);
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
