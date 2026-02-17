<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\LabelExportBatch;
use App\Entity\LabelExportJob;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\LabelExportJobRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

class LabelCatalogExportService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly LabelExportJobRepository $jobRepository,
        private readonly BarcodeGeneratorService $barcodeGenerator,
        private readonly PdfService $pdfService,
    ) {
    }

    public function createJob(Business $business, User $user, array $params): LabelExportJob
    {
        $job = (new LabelExportJob())
            ->setBusiness($business)
            ->setCreatedByUser($user)
            ->setType(LabelExportJob::TYPE_LABELS_CATALOG)
            ->setStatus(LabelExportJob::STATUS_RUNNING)
            ->setParams($params)
            ->setExpiresAt((new \DateTimeImmutable())->modify('+12 hours'));

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $basePath = sprintf('var/exports/labels/%d/%d', $business->getId(), $job->getId());
        $job->setBasePath($basePath);
        $this->entityManager->flush();

        if (!is_dir($basePath)) {
            mkdir($basePath, 0775, true);
        }

        return $job;
    }

    public function generateBatches(LabelExportJob $job): void
    {
        $params = $job->getParams();
        $batchSize = max(1, (int) ($params['batchSize'] ?? 500));
        $business = $job->getBusiness();

        if (!$business instanceof Business) {
            throw new \RuntimeException('Export sin comercio asociado.');
        }

        $lastId = 0;
        $batchIndex = 1;
        $productsTotal = 0;

        while (true) {
            $products = $this->productRepository->findForLabelExportBatch($business, $lastId, $batchSize);
            if ($products === []) {
                break;
            }

            $first = reset($products);
            $last = end($products);
            if (!$first instanceof Product || !$last instanceof Product) {
                break;
            }

            $filename = sprintf('batch-%03d.pdf', $batchIndex);
            $labels = $this->buildLabels($products, $params);

            $pdf = $this->pdfService->generateBytes('product/labels_pdf.html.twig', [
                'business' => $business,
                'labels' => $labels,
                'labelImagePath' => $business->getLabelImagePath(),
                'options' => $this->buildOptions($params),
            ]);

            file_put_contents($job->getBasePath().'/'.$filename, $pdf);

            $batch = (new LabelExportBatch())
                ->setJob($job)
                ->setBatchIndex($batchIndex)
                ->setFromProductId($first->getId())
                ->setToProductId($last->getId())
                ->setProductsCount(count($products))
                ->setFilename($filename)
                ->setStatus(LabelExportBatch::STATUS_READY);

            $this->entityManager->persist($batch);

            $lastId = (int) $last->getId();
            $productsTotal += count($products);
            $batchIndex++;

            $this->entityManager->flush();
            $this->entityManager->clear();
            $job = $this->jobRepository->find($job->getId());
            if (!$job instanceof LabelExportJob) {
                throw new \RuntimeException('No se pudo recargar el export.');
            }
            $business = $job->getBusiness();
            if (!$business instanceof Business) {
                throw new \RuntimeException('No se pudo recargar el comercio del export.');
            }
        }

        $job->setBatchesCount(max(0, $batchIndex - 1));
        $job->setTotalProducts($productsTotal);

        if ($job->getBatchesCount() > 0) {
            $this->buildZip($job);
        }
    }

    public function buildZip(LabelExportJob $job): void
    {
        $zipPath = $job->getBasePath().'/labels.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear ZIP.');
        }

        foreach ($job->getBatches() as $batch) {
            $batchPath = $job->getBasePath().'/'.$batch->getFilename();
            if (is_file($batchPath)) {
                $zip->addFile($batchPath, $batch->getFilename());
            }
        }

        $zip->close();
        $job->setZipFilename('labels.zip');
    }

    public function setJobReady(LabelExportJob $job): void
    {
        $job->setStatus(LabelExportJob::STATUS_READY)
            ->setErrorMessage(null);
        $this->entityManager->flush();
    }

    public function setJobFailed(LabelExportJob $job, string $message): void
    {
        $job->setStatus(LabelExportJob::STATUS_FAILED)
            ->setErrorMessage($message);
        $this->entityManager->flush();
    }

    /** @param Product[] $products */
    private function buildLabels(array $products, array $params): array
    {
        $labels = [];
        $includeBarcode = !empty($params['includeBarcode']);
        $barcodeSource = ($params['barcodeSource'] ?? 'ean') === 'sku' ? 'sku' : 'ean';
        $labelsPerProduct = max(1, (int) ($params['labelsPerProduct'] ?? 1));

        foreach ($products as $product) {
            $barcodeValue = $this->resolveBarcodeValue($product, $includeBarcode, $barcodeSource);
            $barcodeDataUri = null;

            if ($barcodeValue !== null) {
                $barcodeType = $barcodeSource === 'sku' ? 'CODE128' : (preg_match('/^\d{13}$/', $barcodeValue) === 1 ? 'EAN13' : 'CODE128');
                $barcodeDataUri = $this->barcodeGenerator->generatePngDataUri($barcodeValue, $barcodeType);
            }

            for ($i = 0; $i < $labelsPerProduct; $i++) {
                $labels[] = [
                    'product' => $product,
                    'barcodeValue' => $barcodeValue,
                    'barcodeDataUri' => $barcodeDataUri,
                ];
            }
        }

        return $labels;
    }

    private function buildOptions(array $params): array
    {
        return [
            'includeBarcode' => !empty($params['includeBarcode']),
            'includeLabelImage' => !empty($params['includeLabelImage']),
            'barcodeSource' => ($params['barcodeSource'] ?? 'ean') === 'sku' ? 'sku' : 'ean',
            'showPrice' => !empty($params['showPrice']),
            'showOnlyName' => !empty($params['showOnlyName']),
        ];
    }

    private function resolveBarcodeValue(Product $product, bool $includeBarcode, string $barcodeSource): ?string
    {
        if (!$includeBarcode) {
            return null;
        }

        if ($barcodeSource === 'sku') {
            return $product->getSku();
        }

        return $product->getBarcode() ?: null;
    }
}
