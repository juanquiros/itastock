<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\LabelExportJob;
use App\Entity\User;
use App\Form\ProductLabelFilterType;
use App\Message\GenerateLabelExportJobMessage;
use App\Repository\LabelExportBatchRepository;
use App\Repository\LabelExportJobRepository;
use App\Security\BusinessContext;
use App\Service\LabelExportFilesystem;
use App\Service\LabelExportPreparationService;
use App\Service\PdfService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
class ProductLabelController extends AbstractController
{
    public function __construct(
        private readonly BusinessContext $businessContext,
        private readonly LabelExportPreparationService $preparationService,
    ) {
    }

    #[Route('/app/admin/products/labels', name: 'app_product_labels', methods: ['GET', 'POST'])]
    public function index(Request $request, PdfService $pdfService, EntityManagerInterface $entityManager, MessageBusInterface $bus): Response
    {
        $business = $this->requireBusinessContext();

        $form = $this->createForm(ProductLabelFilterType::class, [
            'includeBarcode' => true,
            'barcodeSource' => 'ean',
            'showPrice' => true,
            'showOnlyName' => false,
            'includeLabelImage' => false,
            'labelsPerProduct' => 1,
        ], [
            'current_business' => $business,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $filters = $this->buildFilters($form->getData());
            $products = $this->preparationService->findProducts($business, $filters);
            $totalProducts = count($products);

            if ($totalProducts <= 50) {
                $labels = $this->preparationService->buildLabels($products, $filters);

                return $pdfService->render('product/labels_pdf.html.twig', [
                    'business' => $business,
                    'labels' => $labels,
                    'labelImagePath' => $business->getLabelImagePath(),
                    'options' => [
                        'includeBarcode' => $this->preparationService->toBool($filters['includeBarcode'] ?? '0'),
                        'includeLabelImage' => $this->preparationService->toBool($filters['includeLabelImage'] ?? '0'),
                        'barcodeSource' => ($filters['barcodeSource'] ?? 'ean') === 'sku' ? 'sku' : 'ean',
                        'showPrice' => $this->preparationService->toBool($filters['showPrice'] ?? '0'),
                        'showOnlyName' => $this->preparationService->toBool($filters['showOnlyName'] ?? '0'),
                    ],
                ], 'etiquetas-productos.pdf');
            }

            $user = $this->getUser();
            if (!$user instanceof User) {
                throw $this->createAccessDeniedException();
            }

            $job = (new LabelExportJob())
                ->setBusiness($business)
                ->setCreatedBy($user)
                ->setStatus(LabelExportJob::STATUS_QUEUED)
                ->setTotalProducts($totalProducts)
                ->setBatchSize(50)
                ->setProgressPercent(0)
                ->setProgressText('Trabajo encolado')
                ->setFilters($filters);

            $entityManager->persist($job);
            $entityManager->flush();

            $bus->dispatch(new GenerateLabelExportJobMessage((int) $job->getId()));

            return $this->redirectToRoute('app_exports_labels_show', ['id' => $job->getId()]);
        }

        return $this->render('product/labels.html.twig', [
            'form' => $form,
            'business' => $business,
        ]);
    }

    #[Route('/app/admin/exports/labels', name: 'app_exports_labels_index', methods: ['GET'])]
    public function exportsIndex(LabelExportJobRepository $jobRepository): Response
    {
        $business = $this->requireBusinessContext();

        return $this->render('exports/labels_index.html.twig', [
            'jobs' => $jobRepository->findRecentForBusiness($business),
        ]);
    }

    #[Route('/app/admin/exports/labels/{id}', name: 'app_exports_labels_show', methods: ['GET'])]
    public function exportsShow(LabelExportJob $job): Response
    {
        $this->denyAccessUnlessGrantedToJob($job);

        return $this->render('exports/labels_show.html.twig', [
            'job' => $job,
        ]);
    }

    #[Route('/app/admin/exports/labels/{id}/status', name: 'app_exports_labels_status', methods: ['GET'])]
    public function exportStatus(LabelExportJob $job): JsonResponse
    {
        $this->denyAccessUnlessGrantedToJob($job);

        return $this->json([
            'status' => $job->getStatus(),
            'progressPercent' => $job->getProgressPercent(),
            'progressText' => $job->getProgressText(),
            'doneBatches' => $job->getDoneBatches(),
            'totalBatches' => $job->getTotalBatches(),
            'errorMessage' => $job->getErrorMessage(),
            'isReady' => $job->getStatus() === LabelExportJob::STATUS_READY,
        ]);
    }

    #[Route('/app/admin/exports/labels/{id}/download/zip', name: 'app_exports_labels_download_zip', methods: ['GET'])]
    public function downloadZip(LabelExportJob $job, LabelExportFilesystem $filesystem): Response
    {
        $this->denyAccessUnlessGrantedToJob($job);

        if ($job->getStatus() !== LabelExportJob::STATUS_READY || $job->getZipFilename() === null) {
            throw $this->createNotFoundException('El ZIP aún no está disponible.');
        }

        $path = $filesystem->getJobDir($job).'/'.$job->getZipFilename();
        if (!is_file($path)) {
            throw $this->createNotFoundException('Archivo ZIP no encontrado.');
        }

        return new BinaryFileResponse($path);
    }

    #[Route('/app/admin/exports/labels/{id}/download/batch/{batchId}', name: 'app_exports_labels_download_batch', methods: ['GET'])]
    public function downloadBatch(LabelExportJob $job, int $batchId, LabelExportBatchRepository $batchRepository, LabelExportFilesystem $filesystem): Response
    {
        $this->denyAccessUnlessGrantedToJob($job);

        $batch = $batchRepository->find($batchId);
        if ($batch === null || $batch->getJob()?->getId() !== $job->getId() || $batch->getFilename() === null) {
            throw $this->createNotFoundException('Lote no encontrado.');
        }

        $path = $filesystem->getJobDir($job).'/'.$batch->getFilename();
        if (!is_file($path)) {
            throw $this->createNotFoundException('Archivo del lote no encontrado.');
        }

        return new BinaryFileResponse($path);
    }

    private function denyAccessUnlessGrantedToJob(LabelExportJob $job): void
    {
        $business = $this->requireBusinessContext();
        if ($job->getBusiness()?->getId() !== $business->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function buildFilters(array $data): array
    {
        $productIds = $this->extractIds($data['products'] ?? []);
        $categoryIds = $this->extractIds($data['categories'] ?? []);
        $brandIds = $this->extractIds($data['brands'] ?? []);

        return array_filter([
            'products' => $this->serializeIds($productIds),
            'categories' => $this->serializeIds($categoryIds),
            'brands' => $this->serializeIds($brandIds),
            'updatedSince' => $data['updatedSince']?->format('Y-m-d'),
            'includeBarcode' => !empty($data['includeBarcode']) ? '1' : '0',
            'includeLabelImage' => !empty($data['includeLabelImage']) ? '1' : '0',
            'barcodeSource' => $data['barcodeSource'] ?: 'ean',
            'showPrice' => !empty($data['showPrice']) ? '1' : '0',
            'showOnlyName' => !empty($data['showOnlyName']) ? '1' : '0',
            'labelsPerProduct' => (string) max(1, (int) ($data['labelsPerProduct'] ?? 1)),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /** @param iterable<int, object> $entities @return int[] */
    private function extractIds(iterable $entities): array
    {
        $ids = [];
        foreach ($entities as $entity) {
            if (method_exists($entity, 'getId') && is_int($entity->getId())) {
                $ids[] = $entity->getId();
            }
        }

        return array_values(array_unique($ids));
    }

    private function serializeIds(array $ids): string
    {
        return implode(',', $ids);
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }
}
