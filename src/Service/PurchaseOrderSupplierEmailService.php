<?php

namespace App\Service;

use App\Entity\PurchaseOrder;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PurchaseOrderSupplierEmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly PdfService $pdfService,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $mailFrom,
        private readonly string $appName,
    ) {
    }

    public function send(PurchaseOrder $order): array
    {
        $generatedAt = new \DateTimeImmutable();
        $supplier = $order->getSupplier();

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, $this->appName))
            ->to((string) $supplier?->getEmail())
            ->subject(sprintf('Pedido #%d', $order->getId()))
            ->htmlTemplate('emails/purchase_order/supplier_order.html.twig')
            ->context([
                'order' => $order,
                'generatedAt' => $generatedAt,
                'appName' => $this->appName,
                'headerSubtitle' => 'Detalle de pedido para proveedor',
                'termsUrl' => $this->urlGenerator->generate('public_terms', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);

        $pdfFailed = false;
        try {
            $bytes = $this->pdfService->generateBytes('purchase_order/pdf_supplier_no_prices.html.twig', [
                'order' => $order,
                'generatedAt' => $generatedAt,
            ]);
            $email->attach($bytes, sprintf('pedido-%d.pdf', $order->getId()), 'application/pdf');
        } catch (\Throwable $exception) {
            $pdfFailed = true;
            $this->logger->warning('No se pudo adjuntar el PDF del pedido al email del proveedor.', [
                'orderId' => $order->getId(),
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $this->mailer->send($email);
        } catch (\Throwable $exception) {
            $this->logger->error('No se pudo enviar el email del pedido al proveedor.', [
                'orderId' => $order->getId(),
                'error' => $exception->getMessage(),
            ]);

            return [
                'sent' => false,
                'pdf_failed' => $pdfFailed,
            ];
        }

        return [
            'sent' => true,
            'pdf_failed' => $pdfFailed,
        ];
    }
}
