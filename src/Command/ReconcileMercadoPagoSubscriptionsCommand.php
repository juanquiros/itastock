<?php

namespace App\Command;

use App\Entity\Business;
use App\Entity\Subscription;
use App\Service\MPSubscriptionManager;
use App\Service\PlatformNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:mp:reconcile-subscriptions',
    description: 'Reconcile Mercado Pago subscriptions for businesses with billing enabled.'
)]
class ReconcileMercadoPagoSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MPSubscriptionManager $subscriptionManager,
        private readonly PlatformNotificationService $platformNotificationService,
        #[Autowire('%kernel.logs_dir%')] private readonly string $logDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $businesses = $this->entityManager
            ->getRepository(Business::class)
            ->createQueryBuilder('business')
            ->innerJoin('business.subscription', 'subscription')
            ->andWhere('subscription.status != :statusCanceled')
            ->setParameter('statusCanceled', Subscription::STATUS_CANCELED)
            ->getQuery()
            ->getResult();

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0775, true);
        }
        $logPath = rtrim($this->logDir, '/').'/mp_reconcile.log';

        $processed = 0;
        foreach ($businesses as $business) {
            if (!$business instanceof Business) {
                continue;
            }

            $result = $this->subscriptionManager->reconcileBusinessSubscriptions($business);
            $processed++;

            $logLine = sprintf(
                "[%s] business_id=%d active_before=%d active_after=%d kept=%s canceled=%s stale_pending=%d\n",
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                (int) $business->getId(),
                $result->getActiveBefore(),
                $result->getActiveAfter(),
                $result->getKeptPreapprovalId() ?? '-',
                $result->getCanceledPreapprovals() === [] ? '-' : implode(',', $result->getCanceledPreapprovals()),
                $result->getStalePendingCanceled(),
            );
            file_put_contents($logPath, $logLine, FILE_APPEND);

            if ($result->hasInconsistency()) {
                $this->platformNotificationService->notifySubscriptionInconsistency(
                    $business,
                    $result->getActiveBefore()
                );
            }
        }

        $output->writeln(sprintf('Processed %d business(es).', $processed));

        return Command::SUCCESS;
    }
}
