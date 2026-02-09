<?php

namespace App\Service;

use App\Entity\ArcaCreditNote;
use App\Entity\ArcaInvoice;
use App\Entity\BusinessArcaConfig;
use App\Entity\BusinessUser;
use App\Entity\Sale;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class ArcaCreditNoteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ArcaWsaaService $wsaaService,
        private readonly ArcaWsfeService $wsfeService,
    ) {
    }

    public function createForSale(
        Sale $sale,
        ArcaInvoice $invoice,
        BusinessArcaConfig $config,
        BusinessUser $membership,
        ?User $user,
        ?string $reason
    ): ArcaCreditNote {
        $note = new ArcaCreditNote();
        $note->setBusiness($sale->getBusiness());
        $note->setSale($sale);
        $note->setRelatedInvoice($invoice);
        $note->setCreatedBy($user);
        $note->setStatus(ArcaCreditNote::STATUS_DRAFT);
        $note->setArcaPosNumber($membership->getArcaPosNumber() ?? 1);
        $note->setCbteTipo($this->resolveCbteTipo($config));
        $note->setIssuedAt(new DateTimeImmutable());
        $note->setNetAmount($invoice->getNetAmount());
        $note->setVatAmount($invoice->getVatAmount());
        $note->setTotalAmount($invoice->getTotalAmount());
        $note->setItemsSnapshot($invoice->getItemsSnapshot());
        $note->setReason($reason ? trim($reason) : null);

        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $note;
    }

    public function requestCae(ArcaCreditNote $note, ArcaInvoice $invoice, BusinessArcaConfig $config): void
    {
        $note->setStatus(ArcaCreditNote::STATUS_REQUESTED);
        $this->entityManager->flush();

        try {
            $tokenSign = $this->wsaaService->getTokenSign($note->getBusiness(), $config, 'wsfe');
            $response = $this->wsfeService->requestCaeForCreditNote($note, $config, $tokenSign, $invoice);

            $note->setArcaRawRequest($response['request']);
            $note->setArcaRawResponse($response['response']);
            $note->setCbteNumero($response['cbteNumero']);
            $note->setCae($response['cae']);
            $note->setCaeDueDate($response['caeDueDate']);

            if ($note->getCae()) {
                $note->setStatus(ArcaCreditNote::STATUS_AUTHORIZED);
            } else {
                $note->setStatus(ArcaCreditNote::STATUS_REJECTED);
            }
        } catch (\Throwable $exception) {
            $note->setStatus(ArcaCreditNote::STATUS_REJECTED);
            $note->setArcaRawResponse([
                'error' => $exception->getMessage(),
            ]);
        }

        $this->entityManager->flush();
    }

    private function resolveCbteTipo(BusinessArcaConfig $config): string
    {
        return $config->getTaxPayerType() === BusinessArcaConfig::TAX_PAYER_RESPONSABLE_INSCRIPTO
            ? ArcaCreditNote::CBTE_NC_B
            : ArcaCreditNote::CBTE_NC_C;
    }
}
