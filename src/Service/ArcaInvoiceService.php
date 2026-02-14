<?php

namespace App\Service;

use App\Entity\ArcaInvoice;
use App\Entity\BusinessArcaConfig;
use App\Entity\BusinessUser;
use App\Entity\Sale;
use App\Entity\SaleItem;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class ArcaInvoiceService
{
    public const PRICE_MODE_HISTORIC = 'historic';
    public const PRICE_MODE_CURRENT = 'current';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PricingService $pricingService,
        private readonly ArcaWsaaService $wsaaService,
        private readonly ArcaWsfeService $wsfeService,
    ) {
    }

    public function buildInvoiceFromSale(
        Sale $sale,
        User $user,
        BusinessUser $membership,
        BusinessArcaConfig $config,
        string $priceMode
    ): ArcaInvoice {
        $invoice = new ArcaInvoice();
        $invoice->setBusiness($sale->getBusiness());
        $invoice->setSale($sale);
        $invoice->setCreatedBy($user);
        $invoice->setStatus(ArcaInvoice::STATUS_DRAFT);
        $invoice->setArcaPosNumber($membership->getArcaPosNumber() ?? 1);
        $invoice->setIssuedAt(new DateTimeImmutable());
        $invoice->setCbteTipo($this->resolveCbteTipo($config));

        $snapshot = $this->buildItemsSnapshot($sale, $priceMode, $config);
        $invoice->setItemsSnapshot($snapshot['items']);
        $invoice->setNetAmount($snapshot['netAmount']);
        $invoice->setVatAmount($snapshot['vatAmount']);
        $invoice->setTotalAmount($snapshot['totalAmount']);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    public function requestCae(ArcaInvoice $invoice, BusinessArcaConfig $config): void
    {
        $invoice->setStatus(ArcaInvoice::STATUS_REQUESTED);
        $this->entityManager->flush();

        try {
            $tokenSign = $this->wsaaService->getTokenSign($invoice->getBusiness(), $config, 'wsfe');
            $response = $this->wsfeService->requestCae($invoice, $config, $tokenSign);

            $invoice->setArcaRawRequest($response['request']);
            $invoice->setArcaRawResponse($response['response']);
            $invoice->setCbteNumero($response['cbteNumero']);
            $invoice->setCae($response['cae']);
            $invoice->setCaeDueDate($response['caeDueDate']);

            if ($invoice->getCae()) {
                $invoice->setStatus(ArcaInvoice::STATUS_AUTHORIZED);
            } else {
                $invoice->setStatus(ArcaInvoice::STATUS_REJECTED);
            }
        } catch (\Throwable $exception) {
            $invoice->setStatus(ArcaInvoice::STATUS_REJECTED);
            $invoice->setArcaRawResponse([
                'error' => $exception->getMessage(),
            ]);
        }

        $this->entityManager->flush();
    }

    private function resolveCbteTipo(BusinessArcaConfig $config): string
    {
        return $config->getTaxPayerType() === BusinessArcaConfig::TAX_PAYER_RESPONSABLE_INSCRIPTO
            ? ArcaInvoice::CBTE_FACTURA_B
            : ArcaInvoice::CBTE_FACTURA_C;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, netAmount: string, vatAmount: string, totalAmount: string}
     */
    private function buildItemsSnapshot(Sale $sale, string $priceMode, BusinessArcaConfig $config): array
    {
        $items = [];
        $total = 0.0;
        $netTotal = 0.0;
        $vatTotal = 0.0;
        $customer = $sale->getCustomer();

        foreach ($sale->getItems() as $item) {
            $unitPrice = (float) $item->getUnitPrice();
            $lineTotal = (float) $item->getLineTotal();

            if ($priceMode === self::PRICE_MODE_CURRENT && $item->getProduct()) {
                $unitPrice = $this->pricingService->resolveUnitPrice($item->getProduct(), $customer);
                $lineTotal = $unitPrice * (float) $item->getQty();
            }

            $ivaRate = $this->resolveIvaRate($item);
            $lineNet = $lineTotal;
            $lineVat = 0.0;

            if ($ivaRate > 0.0) {
                $lineNet = $lineTotal / (1 + ($ivaRate / 100));
                $lineVat = $lineTotal - $lineNet;
            }

            $total += $lineTotal;
            $netTotal += $lineNet;
            $vatTotal += $lineVat;

            $items[] = [
                'description' => $item->getDescription(),
                'qty' => (float) $item->getQty(),
                'unitPrice' => round($unitPrice, 2),
                'lineTotal' => round($lineTotal, 2),
                'ivaRate' => $ivaRate,
            ];
        }

        if ($config->getTaxPayerType() === BusinessArcaConfig::TAX_PAYER_MONOTRIBUTO) {
            $netTotal = $total;
            $vatTotal = 0.0;
        }

        return [
            'items' => $items,
            'netAmount' => number_format($netTotal, 2, '.', ''),
            'vatAmount' => number_format($vatTotal, 2, '.', ''),
            'totalAmount' => number_format($total, 2, '.', ''),
        ];
    }

    private function resolveIvaRate(SaleItem $item): float
    {
        if ($item->getIvaRate() !== null) {
            return (float) $item->getIvaRate();
        }

        $product = $item->getProduct();
        if ($product && $product->getIvaRate() !== null) {
            return (float) $product->getIvaRate();
        }

        if ($product && $product->getCategory() && $product->getCategory()->getIvaRate() !== null) {
            return (float) $product->getCategory()->getIvaRate();
        }

        return 21.0;
    }
}
