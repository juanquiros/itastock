<?php

namespace App\Command;

use App\Entity\BusinessArcaConfig;
use App\Service\ArcaSoapClientFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:arca:wsfe-wsdl-test', description: 'Prueba carga de WSDL WSFE con la configuración actual')]
class ArcaWsfeWsdlTestCommand extends Command
{
    public function __construct(
        private readonly ArcaSoapClientFactory $soapClientFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_OPTIONAL, 'homo|prod', 'prod')
            ->addOption('location', null, InputOption::VALUE_OPTIONAL, 'Location WSFE opcional para forzar endpoint');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $envOpt = strtoupper((string) $input->getOption('env'));
        $location = trim((string) $input->getOption('location'));

        if (!in_array($envOpt, ['PROD', 'HOMO'], true)) {
            $io->error('El parámetro --env debe ser homo o prod.');

            return Command::INVALID;
        }

        $config = new BusinessArcaConfig();
        $config->setArcaEnvironment($envOpt);

        try {
            $usedWsdl = null;
            $this->soapClientFactory->createWsfeClientForLocation(
                $config,
                $location !== '' ? $location : null,
                $usedWsdl,
            );

            $io->success(sprintf('OK WSFE WSDL env=%s wsdl=%s', $envOpt, $usedWsdl ?? 'n/a'));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error(sprintf('FAIL WSFE WSDL env=%s error=%s', $envOpt, $exception->getMessage()));

            return Command::FAILURE;
        }
    }
}
