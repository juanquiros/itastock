<?php

namespace App\Controller;

use App\Entity\Business;
use App\Form\BusinessArcaConfigType;
use App\Repository\BusinessArcaConfigRepository;
use App\Security\BusinessContext;
use App\Service\ArcaPemNormalizer;
use App\Service\ArcaWsaaService;
use App\Service\ArcaWsfeService;
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
        private readonly ArcaPemNormalizer $pemNormalizer,
        private readonly ArcaWsaaService $arcaWsaaService,
        private readonly ArcaWsfeService $arcaWsfeService,
    ) {
    }

    #[Route('/arca', name: 'arca', methods: ['GET', 'POST'])]
    public function arca(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $config = $this->configRepository->getOrCreate($business);
        $business->setArcaConfig($config);

        $receiverOptions = $this->arcaWsfeService->getCondicionIvaReceptorOptions($config);
        $receiverHelp = 'ARCA lo exige para Factura C / Consumidor Final (RG 5616).';
        if ($this->arcaWsfeService->getCondicionIvaReceptorError($config)) {
            $receiverHelp .= ' No se pudieron cargar opciones desde ARCA. Podés guardar igual y reintentar.';
            $this->addFlash('warning', 'No se pudo cargar el catálogo de condiciones IVA del receptor desde ARCA.');
        }

        $form = $this->createForm(BusinessArcaConfigType::class, $config, [
            'receiver_iva_options' => $receiverOptions,
            'receiver_iva_help' => $receiverHelp,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->applyUploadedPemFiles($form, $config);
            $this->normalizePemFields($config);

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
            $this->pemNormalizer->validate(
                (string) $config->getCertPem(),
                (string) $config->getPrivateKeyPem(),
                $config->getPassphrase()
            );
            $this->arcaWsaaService->getTokenSign($business, $config, 'wsfe');
            $this->addFlash('success', 'Conexión ARCA exitosa.');
        } catch (\Throwable $exception) {
            $detail = substr($exception->getMessage(), 0, 500);
            $this->addFlash(
                'danger',
                "Certificado o clave inválidos. Subí los archivos .crt/.key o pegá el PEM completo.\nDetalle: {$detail}"
            );
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

        if ($errors === []) {
            try {
                $this->pemNormalizer->validate(
                    (string) $config->getCertPem(),
                    (string) $config->getPrivateKeyPem(),
                    $config->getPassphrase()
                );
            } catch (\Throwable $exception) {
                $errors[] = substr($exception->getMessage(), 0, 500);
            }
        }

        return $errors;
    }

    private function applyUploadedPemFiles(\Symfony\Component\Form\FormInterface $form, \App\Entity\BusinessArcaConfig $config): void
    {
        $certFile = $form->get('certFile')->getData();
        if ($certFile) {
            $certContent = file_get_contents($certFile->getPathname());
            if ($certContent !== false) {
                $config->setCertPem($certContent);
            }
        }

        $keyFile = $form->get('keyFile')->getData();
        if ($keyFile) {
            $keyContent = file_get_contents($keyFile->getPathname());
            if ($keyContent !== false) {
                $config->setPrivateKeyPem($keyContent);
            }
        }
    }

    private function normalizePemFields(\App\Entity\BusinessArcaConfig $config): void
    {
        if ($config->getCertPem()) {
            $config->setCertPem($this->pemNormalizer->normalizeCert($config->getCertPem()));
        }

        if ($config->getPrivateKeyPem()) {
            $config->setPrivateKeyPem($this->pemNormalizer->normalizeKey($config->getPrivateKeyPem()));
        }
    }
}
