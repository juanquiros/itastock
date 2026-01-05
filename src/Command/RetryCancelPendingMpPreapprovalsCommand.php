<?php

namespace App\Command;

use App\Entity\MercadoPagoSubscriptionLink;
use App\Exception\MercadoPagoApiException;
use App\Repository\MercadoPagoSubscriptionLinkRepository;
use App\Service\MercadoPagoClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:mp:retry-cancel-pending',
    description: 'Retry Mercado Pago preapproval cancellations marked as pending.'
)]
class RetryCancelPendingMpPreapprovalsCommand extends Command
{
    public function __construct(
        private readonly MercadoPagoSubscriptionLinkRepository $subscriptionLinkRepository,
        private readonly MercadoPagoClient $mercadoPagoClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $links = $this->subscriptionLinkRepository->findCancelPending(50);
        if ($links === []) {
            $output->writeln('No pending cancellations found.');

            return Command::SUCCESS;
        }

        $processed = 0;
        foreach ($links as $link) {
            if (!$link instanceof MercadoPagoSubscriptionLink) {
                continue;
            }

            $processed++;
            $link->setLastAttemptAt(new \DateTimeImmutable());

            try {
                $this->mercadoPagoClient->cancelPreapproval($link->getMpPreapprovalId());
                $link->setStatus('CANCELED');
                $link->setIsPrimary(false);
            } catch (MercadoPagoApiException $exception) {
                $this->logger->warning('Failed retrying MP preapproval cancellation.', [
                    'mp_preapproval_id' => $link->getMpPreapprovalId(),
                    'business_id' => $link->getBusiness()?->getId(),
                    'correlation_id' => $exception->getCorrelationId(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Processed %d pending cancellation(s).', $processed));

        return Command::SUCCESS;
    }
}
