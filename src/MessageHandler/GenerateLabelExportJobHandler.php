<?php

namespace App\MessageHandler;

use App\Entity\LabelExportBatch;
use App\Entity\LabelExportJob;
use App\Message\GenerateLabelExportJobMessage;
use App\Repository\LabelExportJobRepository;
use App\Repository\ProductRepository;
use App\Service\LabelExportFilesystem;
use App\Service\LabelExportPreparationService;
use App\Service\PdfAssetPathResolver;
use App\Service\WkhtmlPdfService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

#[AsMessageHandler]
class GenerateLabelExportJobHandler
{
    public function __construct(
        private readonly LabelExportJobRepository $jobRepository,
        private readonly ProductRepository $productRepository,
        private readonly LabelExportPreparationService $preparationService,
        private readonly LabelExportFilesystem $filesystem,
        private readonly WkhtmlPdfService $wkhtmlPdfService,
        private readonly PdfAssetPathResolver $assetResolver,
        private readonly Environment $twig,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(GenerateLabelExportJobMessage $message): void
    {
        $job = $this->jobRepository->find($message->getJobId());
        if (!$job instanceof LabelExportJob || $job->getStatus() === LabelExportJob::STATUS_EXPIRED) {
            return;
        }

        try {
            $job->setStatus(LabelExportJob::STATUS_RUNNING)
                ->setStartedAt(new \DateTimeImmutable())
                ->setErrorMessage(null)
                ->setProgressPercent(1)
                ->setProgressText('Preparando exportación...');
            $this->entityManager->flush();

            $filters = $job->getFilters();
            $labelsPerProduct = max(1, (int) ($filters['labelsPerProduct'] ?? 1));
            $batchSize = max(1, $job->getBatchSize());
            $totalProducts = $job->getTotalProducts();
            $totalBatches = (int) ceil($totalProducts / $batchSize);
            $job->setTotalBatches($totalBatches)->setDoneBatches(0);
            $jobDir = $this->filesystem->ensureJobDir($job);

            $lastId = 0;
            $batchIndex = 0;
            while (true) {
                $products = $this->productRepository->findForLabelExportChunk($job->getBusiness(), $filters, $lastId, $batchSize);
                if ($products === []) {
                    break;
                }

                ++$batchIndex;
                $lastId = max(array_map(static fn ($product) => $product->getId() ?? 0, $products));
                $labels = $this->preparationService->buildLabels($products, $filters);
                $logoPath = $this->assetResolver->resolvePublicPath($job->getBusiness()?->getLabelImagePath());
                $html = $this->twig->render('product/labels_pdf.html.twig', [
                    'business' => $job->getBusiness(),
                    'labelImagePath' => $logoPath,
                    'labels' => $labels,
                    'options' => [
                        'includeBarcode' => $this->preparationService->toBool($filters['includeBarcode'] ?? '0'),
                        'includeLabelImage' => $this->preparationService->toBool($filters['includeLabelImage'] ?? '0'),
                        'barcodeSource' => ($filters['barcodeSource'] ?? 'ean') === 'sku' ? 'sku' : 'ean',
                        'showPrice' => $this->preparationService->toBool($filters['showPrice'] ?? '0'),
                        'showOnlyName' => $this->preparationService->toBool($filters['showOnlyName'] ?? '0'),
                    ],
                ]);

                $filename = sprintf('batch-%03d.pdf', $batchIndex);
                $this->wkhtmlPdfService->generateFromHtml($html, $jobDir.'/'.$filename);

                $batch = new LabelExportBatch();
                $batch->setJob($job)
                    ->setBatchIndex($batchIndex)
                    ->setProductCount(count($products) * $labelsPerProduct)
                    ->setFilename($filename)
                    ->setStatus(LabelExportBatch::STATUS_READY);
                $this->entityManager->persist($batch);

                $job->setDoneBatches($batchIndex)
                    ->setProgressPercent((int) floor(($batchIndex / max(1, $totalBatches)) * 100))
                    ->setProgressText(sprintf('Lote %d/%d generado', $batchIndex, max(1, $totalBatches)));

                $this->entityManager->flush();
                gc_collect_cycles();
            }

            $zipFilename = sprintf('etiquetas-%d.zip', $job->getId());
            $zipPath = $jobDir.'/'.$zipFilename;
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('No se pudo crear el ZIP de exportación.');
            }

            foreach (glob($jobDir.'/batch-*.pdf') ?: [] as $pdfPath) {
                $zip->addFile($pdfPath, basename($pdfPath));
            }
            $zip->close();

            $job->setZipFilename($zipFilename)
                ->setStatus(LabelExportJob::STATUS_READY)
                ->setFinishedAt(new \DateTimeImmutable())
                ->setProgressPercent(100)
                ->setProgressText('Exportación lista para descargar.');
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $job->setStatus(LabelExportJob::STATUS_FAILED)
                ->setFinishedAt(new \DateTimeImmutable())
                ->setProgressText('Falló la exportación')
                ->setErrorMessage($exception->getMessage());
            $this->entityManager->flush();
        }
    }
}
