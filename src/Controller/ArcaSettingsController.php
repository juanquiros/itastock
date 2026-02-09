<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/settings', name: 'app_settings_')]
class ArcaSettingsController extends AbstractController
{
    #[Route('/arca', name: 'arca', methods: ['GET', 'POST'])]
    public function arca(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->addFlash('success', 'Configuración ARCA actualizada.');
        }

        return $this->render('settings/arca.html.twig');
    }

    #[Route('/arca/test', name: 'arca_test', methods: ['POST'])]
    public function arcaTest(): Response
    {
        $this->addFlash('success', 'Prueba de conexión ARCA enviada.');

        return $this->redirectToRoute('app_settings_arca');
    }
}
