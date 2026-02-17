<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\LabelExportBatch;
use App\Entity\LabelExportJob;
use App\Form\ProductLabelFilterType;
use App\Repository\LabelExportJobRepository;
use App\Repository\ProductRepository;
use App\Security\BusinessContext;
use App\Service\LabelCatalogExportService;
use App\Service\PdfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
class ProductLabelController extends AbstractController
{
    public function __construct(private readonly BusinessContext $businessContext)
    {
    }

    #[Route('/app/admin/products/labels', name: 'app_product_labels', methods: ['GET', 'POST'])]
    public function index(Request $request, LabelCatalogExportService $labelCatalogExportService): Response
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
            $data = $form->getData();
            $productIds = $this->extractIds($data['products'] ?? []);
            $categoryIds = $this->extractIds($data['categories'] ?? []);
            $brandIds = $this->extractIds($data['brands'] ?? []);
            $labelsPerProduct = max(1, (int) ($data['labelsPerProduct'] ?? 1));

            if ($request->request->has('createOptimizedCatalog')) {
                $batchSize = (int) $form->get('batchSize')->getData();
                $params = [
                    'includeBarcode' => !empty($data['includeBarcode']),
                    'includeLabelImage' => !empty($data['includeLabelImage']),
                    'barcodeSource' => $data['barcodeSource'] ?: 'ean',
                    'showPrice' => !empty($data['showPrice']),
                    'showOnlyName' => !empty($data['showOnlyName']),
                    'labelsPerProduct' => $labelsPerProduct,
                    'batchSize' => in_array($batchSize, [100, 200, 500, 1000], true) ? $batchSize : 200,
                ];

                $job = $labelCatalogExportService->createJob($business, $this->requireUser(), $params);

                try {
                    $labelCatalogExportService->generateBatches($job);
                    $labelCatalogExportService->setJobReady($job);
                    $this->addFlash('success', 'Export optimizado generado correctamente.');
                } catch (\Throwable $e) {
                    $labelCatalogExportService->setJobFailed($job, $e->getMessage());
                    $this->addFlash('danger', 'No se pudo generar el export optimizado.');
                }

                return $this->redirectToRoute('app_label_export_index');
            }

            $params = array_filter([
                'products' => $this->serializeIds($productIds),
                'categories' => $this->serializeIds($categoryIds),
                'brands' => $this->serializeIds($brandIds),
                'updatedSince' => $data['updatedSince']?->format('Y-m-d'),
                'includeBarcode' => !empty($data['includeBarcode']) ? '1' : '0',
                'includeLabelImage' => !empty($data['includeLabelImage']) ? '1' : '0',
                'barcodeSource' => $data['barcodeSource'] ?: 'ean',
                'showPrice' => !empty($data['showPrice']) ? '1' : '0',
                'showOnlyName' => !empty($data['showOnlyName']) ? '1' : '0',
                'labelsPerProduct' => (string) $labelsPerProduct,
            ], static fn ($value) => $value !== null && $value !== '');

            return $this->redirectToRoute('app_product_labels_pdf', $params);
        }

        return $this->render('product/labels.html.twig', [
            'form' => $form,
            'business' => $business,
        ]);
    }

    #[Route('/app/admin/products/labels/pdf', name: 'app_product_labels_pdf', methods: ['GET'])]
    public function pdf(
        Request $request,
        ProductRepository $productRepository,
        PdfService $pdfService,
        LabelCatalogExportService $labelCatalogExportService,
    ): Response {
        $business = $this->requireBusinessContext();

        $productIds = $this->parseIds((string) $request->query->get('products', ''));
        $categoryIds = $this->parseIds((string) $request->query->get('categories', ''));
        $brandIds = $this->parseIds((string) $request->query->get('brands', ''));
        $updatedSince = $this->parseDate((string) $request->query->get('updatedSince', ''));

        if ($productIds !== []) {
            $products = $productRepository->findBy(
                ['business' => $business, 'id' => $productIds],
                ['name' => 'ASC']
            );
        } else {
            $products = $productRepository->findForLabelFilters($business, $categoryIds, $brandIds, $updatedSince);
        }

        $includeBarcode = $this->toBool($request->query->get('includeBarcode', '0'));
        $includeLabelImage = $this->toBool($request->query->get('includeLabelImage', '0'));
        $barcodeSource = $request->query->get('barcodeSource') === 'sku' ? 'sku' : 'ean';
        $showPrice = $this->toBool($request->query->get('showPrice', '0'));
        $showOnlyName = $this->toBool($request->query->get('showOnlyName', '0'));
        $labelsPerProduct = max(1, (int) $request->query->get('labelsPerProduct', 1));

        $labels = $labelCatalogExportService->buildLabels($products, [
            'includeBarcode' => $includeBarcode,
            'barcodeSource' => $barcodeSource,
            'labelsPerProduct' => $labelsPerProduct,
        ]);

        $localLabelImagePath = $this->resolveLocalLabelImagePath($business->getLabelImagePath());

        return $pdfService->render('product/labels_pdf.html.twig', [
            'business' => $business,
            'labels' => $labels,
            'labelImagePath' => $localLabelImagePath,
            'options' => [
                'includeBarcode' => $includeBarcode,
                'includeLabelImage' => $includeLabelImage && $localLabelImagePath !== null,
                'barcodeSource' => $barcodeSource,
                'showPrice' => $showPrice,
                'showOnlyName' => $showOnlyName,
            ],
        ], 'etiquetas-productos.pdf');
    }

    #[Route('/app/admin/exports/labels', name: 'app_label_export_index', methods: ['GET'])]
    public function recentExports(LabelExportJobRepository $jobRepository): Response
    {
        $business = $this->requireBusinessContext();

        return $this->render('exports/labels/index.html.twig', [
            'exports' => $jobRepository->findRecentByBusiness($business),
        ]);
    }

    #[Route('/app/admin/exports/labels/{id}', name: 'app_label_export_show', methods: ['GET'])]
    public function showExport(LabelExportJob $job): Response
    {
        $this->assertCanAccessJob($job);

        return $this->render('exports/labels/show.html.twig', [
            'export' => $job,
        ]);
    }

    #[Route('/app/admin/exports/labels/{id}/zip', name: 'app_label_export_zip', methods: ['GET'])]
    public function downloadZip(LabelExportJob $job): Response
    {
        $this->assertCanAccessJob($job);
        $this->assertNotExpired($job);

        $path = $job->getBasePath().'/'.$job->getZipFilename();
        if (!$job->getZipFilename() || !is_file($path)) {
            throw $this->createNotFoundException('No existe ZIP para este export.');
        }

        return new BinaryFileResponse($path);
    }

    #[Route('/app/admin/exports/labels/{id}/batch/{batchId}', name: 'app_label_export_batch', methods: ['GET'])]
    public function downloadBatch(LabelExportJob $job, int $batchId): Response
    {
        $this->assertCanAccessJob($job);
        $this->assertNotExpired($job);

        $batch = null;
        foreach ($job->getBatches() as $item) {
            if ($item->getId() === $batchId) {
                $batch = $item;
                break;
            }
        }

        if (!$batch instanceof LabelExportBatch) {
            throw $this->createNotFoundException('Lote no encontrado.');
        }

        $path = $job->getBasePath().'/'.$batch->getFilename();
        if (!is_file($path)) {
            throw $this->createNotFoundException('Archivo de lote no encontrado.');
        }

        return new BinaryFileResponse($path);
    }

    private function assertCanAccessJob(LabelExportJob $job): void
    {
        $business = $this->requireBusinessContext();
        if ($job->getBusiness()?->getId() !== $business->getId()) {
            throw $this->createAccessDeniedException('No tenés permisos sobre este export.');
        }
    }

    private function assertNotExpired(LabelExportJob $job): void
    {
        if ($job->isExpired()) {
            throw $this->createNotFoundException('El export está vencido.');
        }
    }



    /**
     * @param iterable<int, object> $entities
     *
     * @return int[]
     */
    private function extractIds(iterable $entities): array
    {
        $ids = [];

        foreach ($entities as $entity) {
            if (method_exists($entity, 'getId')) {
                $id = $entity->getId();
                if (is_int($id)) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return int[]
     */
    private function parseIds(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', $input)));
        $ids = [];

        foreach ($parts as $part) {
            if (ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    private function serializeIds(array $ids): string
    {
        return implode(',', $ids);
    }

    private function parseDate(string $input): ?\DateTimeImmutable
    {
        if ($input === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $input);

        return $date === false ? null : $date->setTime(0, 0, 0);
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function resolveLocalLabelImagePath(?string $labelImagePath): ?string
    {
        if (!is_string($labelImagePath) || trim($labelImagePath) === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $labelImagePath) === 1) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $labelImagePath), '/');
        if ($path === '') {
            return null;
        }

        $absolutePath = $this->getParameter('kernel.project_dir').'/'.$path;

        return is_file($absolutePath) ? $path : null;
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    private function requireUser(): \App\Entity\User
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw new AccessDeniedException('Usuario inválido.');
        }

        return $user;
    }
}
