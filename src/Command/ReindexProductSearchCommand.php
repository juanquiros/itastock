<?php

namespace App\Command;

use App\Entity\Product;
use App\Service\ProductSearchTextBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:products:reindex-search', description: 'Reindexa search_text de todos los productos')]
class ReindexProductSearchCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductSearchTextBuilder $builder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repo = $this->entityManager->getRepository(Product::class);
        $products = $repo->findBy([], ['id' => 'ASC']);

        $io->progressStart(count($products));
        foreach ($products as $product) {
            $product->setSearchText($this->builder->buildForProduct($product));
            $io->progressAdvance();
        }
        $io->progressFinish();
        $this->entityManager->flush();

        $io->success(sprintf('Reindexación finalizada. Productos procesados: %d', count($products)));

        return Command::SUCCESS;
    }
}
