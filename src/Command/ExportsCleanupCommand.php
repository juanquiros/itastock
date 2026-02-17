<?php

namespace App\Command;

use App\Entity\LabelExportJob;
use App\Repository\LabelExportJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:exports:cleanup', description: 'Limpia exportes de etiquetas vencidos.')]
class ExportsCleanupCommand extends Command
{
    public function __construct(
        private readonly LabelExportJobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ttl', null, InputOption::VALUE_OPTIONAL, 'Tiempo de vida de exportes', '12h')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No borra archivos, solo informa.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ttl = (string) $input->getOption('ttl');
        $dryRun = (bool) $input->getOption('dry-run');
        $hours = $this->parseHours($ttl);
        $now = new \DateTimeImmutable();
        $threshold = $now->modify(sprintf('-%d hours', $hours));

        $jobs = $this->jobRepository->createQueryBuilder('j')
            ->andWhere('j.expiresAt <= :threshold')
            ->andWhere('j.status != :expired')
            ->setParameter('threshold', $threshold)
            ->setParameter('expired', LabelExportJob::STATUS_EXPIRED)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($jobs as $job) {
            if (!$job instanceof LabelExportJob) {
                continue;
            }

            $count++;
            $output->writeln(sprintf('Expirando export #%d (%s)', $job->getId(), $job->getBasePath()));

            if ($dryRun) {
                continue;
            }

            $this->deleteDirectory($job->getBasePath());
            $job->setStatus(LabelExportJob::STATUS_EXPIRED);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $output->writeln(sprintf('Procesados %d export(es).', $count));

        return Command::SUCCESS;
    }

    private function parseHours(string $ttl): int
    {
        if (preg_match('/^(\d+)h$/', strtolower(trim($ttl)), $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return 12;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}
