<?php

namespace App\Controller;

use App\Entity\Business;
use App\Form\BusinessArcaConfigType;
use App\Repository\BusinessArcaConfigRepository;
use App\Security\BusinessContext;
use App\Service\ArcaWsaaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/settings', name: 'app_admin_settings_')]
class ArcaSettingsController extends AbstractController
{
    public function __construct(
        private readonly BusinessContext $businessContext,
        private readonly BusinessArcaConfigRepository $arcaConfigRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/arca', name: 'arca', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $config = $this->arcaConfigRepository->getOrCreate($business);
        $business->setArcaConfig($config);

        $form = $this->createForm(BusinessArcaConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($config->isArcaEnabled()) {
                $missing = [];
                if (!$config->getCuitEmisor()) {
                    $missing[] = 'CUIT emisor';
                }
                if (!$config->getCertPem()) {
                    $missing[] = 'certificado';
                }
                if (!$config->getPrivateKeyPem()) {
                    $missing[] = 'key privada';
                }

                if ($missing) {
                    $this->addFlash('danger', sprintf('Completá los campos requeridos: %s.', implode(', ', $missing)));
                } else {
                    $this->entityManager->persist($config);
                    $this->entityManager->flush();

                    $this->addFlash('success', 'Configuración ARCA actualizada.');

                    return $this->redirectToRoute('app_admin_settings_arca');
                }
            } else {
                $this->entityManager->persist($config);
                $this->entityManager->flush();

                $this->addFlash('success', 'Configuración ARCA actualizada.');

                return $this->redirectToRoute('app_admin_settings_arca');
            }
        }

        return $this->render('settings/arca.html.twig', [
            'form' => $form->createView(),
            'business' => $business,
        ]);
    }

    #[Route('/arca/test', name: 'arca_test', methods: ['POST'])]
    public function test(Request $request, ArcaWsaaService $wsaaService): RedirectResponse
    {
        $business = $this->requireBusinessContext();
        $config = $this->arcaConfigRepository->getOrCreate($business);

        if (!$this->isCsrfTokenValid('arca_test', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_admin_settings_arca');
        }

        try {
            $wsaaService->getTokenSign($business, $config, 'wsfe');
            $this->addFlash('success', 'Conexión ARCA exitosa.');
        } catch (\Throwable $exception) {
            $this->addFlash('danger', sprintf('Error al conectar con ARCA: %s', $exception->getMessage()));
        }

        return $this->redirectToRoute('app_admin_settings_arca');
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
