<?php

namespace App\Command;

use App\Entity\LabelExportJob;
use App\Repository\LabelExportJobRepository;
use App\Service\LabelExportFilesystem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:exports:cleanup', description: 'Expira y limpia exportaciones de etiquetas vencidas')]
class ExportsCleanupCommand extends Command
{
    public function __construct(
        private readonly LabelExportJobRepository $jobRepository,
        private readonly LabelExportFilesystem $filesystem,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobs = $this->jobRepository->findExpiredActiveJobs(new \DateTimeImmutable());

        foreach ($jobs as $job) {
            $dir = $this->filesystem->getJobDir($job);
            $this->removeDirectory($dir);
            $job->setStatus(LabelExportJob::STATUS_EXPIRED)
                ->setProgressText('Exportación expirada por TTL (12h)')
                ->setFinishedAt($job->getFinishedAt() ?? new \DateTimeImmutable());
        }

        $this->entityManager->flush();
        $io->success(sprintf('Jobs expirados procesados: %d', count($jobs)));

        return Command::SUCCESS;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        @rmdir($directory);
    }
}
