<?php

namespace App\Command;

use App\Repository\BusinessArcaConfigRepository;
use App\Repository\BusinessRepository;
use App\Service\ArcaWsaaService;
use App\Service\ArcaWsfeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:arca:healthcheck', description: 'Verifica conectividad WSAA/WSFE para un comercio')]
class ArcaHealthcheckCommand extends Command
{
    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly BusinessArcaConfigRepository $arcaConfigRepository,
        private readonly ArcaWsaaService $wsaaService,
        private readonly ArcaWsfeService $wsfeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('business-id', null, InputOption::VALUE_REQUIRED, 'ID del comercio')
            ->addOption('service', null, InputOption::VALUE_OPTIONAL, 'wsaa|wsfe|all', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $businessId = (int) $input->getOption('business-id');
        $service = strtolower((string) $input->getOption('service'));

        if ($businessId <= 0) {
            $io->error('Debe indicar --business-id válido.');

            return Command::INVALID;
        }

        if (!in_array($service, ['wsaa', 'wsfe', 'all'], true)) {
            $io->error('Opción --service inválida. Use wsaa, wsfe o all.');

            return Command::INVALID;
        }

        $business = $this->businessRepository->find($businessId);
        if (!$business) {
            $io->error(sprintf('No existe business id=%d.', $businessId));

            return Command::FAILURE;
        }

        $config = $this->arcaConfigRepository->findOneBy(['business' => $business]);
        if (!$config) {
            $io->error(sprintf('No existe configuración ARCA para business id=%d.', $businessId));

            return Command::FAILURE;
        }

        if (!$config->isArcaEnabled() || !$config->getCuitEmisor() || !$config->getCertPem() || !$config->getPrivateKeyPem()) {
            $io->error('Configuración ARCA incompleta o deshabilitada (enabled/cuit/cert/key).');

            return Command::FAILURE;
        }

        $tokenSign = null;
        $ok = true;

        if (in_array($service, ['wsaa', 'all'], true)) {
            try {
                $tokenSign = $this->wsaaService->getTokenSign($business, $config, 'wsfe');
                $io->writeln(sprintf('[OK] WSAA %s business=%d', $config->getArcaEnvironment(), $businessId));
            } catch (\Throwable $exception) {
                $io->writeln(sprintf('[FAIL] WSAA %s business=%d error=%s', $config->getArcaEnvironment(), $businessId, $exception->getMessage()));
                $ok = false;
            }
        }

        if (in_array($service, ['wsfe', 'all'], true)) {
            try {
                $tokenSign ??= $this->wsaaService->getTokenSign($business, $config, 'wsfe');
                $this->wsfeService->requestTransportCheck($config, $tokenSign, 1, 11);
                $io->writeln(sprintf('[OK] WSFE %s business=%d', $config->getArcaEnvironment(), $businessId));
            } catch (\Throwable $exception) {
                $io->writeln(sprintf('[FAIL] WSFE %s business=%d error=%s', $config->getArcaEnvironment(), $businessId, $exception->getMessage()));
                $ok = false;
            }
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
