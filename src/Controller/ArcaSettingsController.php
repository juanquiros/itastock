<?php

namespace App\Controller;

use App\Entity\Business;
use App\Form\BusinessArcaConfigType;
use App\Repository\BusinessArcaConfigRepository;
use App\Security\BusinessContext;
use App\Service\ArcaWsaaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/settings', name: 'app_settings_')]
class ArcaSettingsController extends AbstractController
{
    public function __construct(
        private readonly BusinessContext $businessContext,
        private readonly BusinessArcaConfigRepository $configRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ArcaWsaaService $arcaWsaaService,
    ) {
    }

    #[Route('/arca', name: 'arca', methods: ['GET', 'POST'])]
    public function arca(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $config = $this->configRepository->getOrCreate($business);
        $business->setArcaConfig($config);

        $form = $this->createForm(BusinessArcaConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            foreach ($this->validateConfig($config) as $message) {
                $form->addError(new FormError($message));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($config);
            $this->entityManager->flush();

            $this->addFlash('success', 'Configuración ARCA actualizada.');

            return $this->redirectToRoute('app_settings_arca');
        }

        return $this->render('settings/arca.html.twig', [
            'form' => $form->createView(),
            'config' => $config,
        ]);
    }

    #[Route('/arca/test', name: 'arca_test', methods: ['POST'])]
    public function arcaTest(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $config = $this->configRepository->findOneBy(['business' => $business]);

        if (!$this->isCsrfTokenValid('arca_test', (string) $request->request->get('_token'))) {
            throw new AccessDeniedException('Token CSRF inválido.');
        }

        if (!$config) {
            $this->addFlash('danger', 'Primero debés guardar la configuración ARCA.');

            return $this->redirectToRoute('app_settings_arca');
        }

        try {
            $errors = $this->validateConfig($config);
            if ($errors !== []) {
                throw new \RuntimeException($errors[0]);
            }
            $this->arcaWsaaService->getTokenSign($business, $config, 'wsfe');
            $this->addFlash('success', 'Conexión ARCA exitosa.');
        } catch (\Throwable $exception) {
            $this->addFlash('danger', sprintf('Error al conectar con ARCA: %s', $exception->getMessage()));
        }

        return $this->redirectToRoute('app_settings_arca');
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    /**
     * @return string[]
     */
    private function validateConfig(\App\Entity\BusinessArcaConfig $config): array
    {
        if (!$config->isArcaEnabled()) {
            return [];
        }

        $errors = [];

        if (!$config->getCuitEmisor()) {
            $errors[] = 'El CUIT es obligatorio cuando ARCA está habilitado.';
        }

        if (!$config->getCertPem() || !$config->getPrivateKeyPem()) {
            $errors[] = 'Certificado y key privada son obligatorios cuando ARCA está habilitado.';
        }

        return $errors;
    }
}
