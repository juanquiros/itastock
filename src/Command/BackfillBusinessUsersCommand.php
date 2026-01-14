<?php

namespace App\Command;

use App\Entity\BusinessUser;
use App\Entity\User;
use App\Repository\BusinessUserRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:business-users:backfill',
    description: 'Crea o reactiva membresías BusinessUser para usuarios existentes con business asignado.',
)]
class BackfillBusinessUsersCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly BusinessUserRepository $businessUserRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $users = $this->userRepository->createQueryBuilder('u')
            ->andWhere('u.business IS NOT NULL')
            ->getQuery()
            ->getResult();

        $created = 0;
        $reactivated = 0;

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $business = $user->getBusiness();
            if (!$business) {
                continue;
            }

            $membership = $this->businessUserRepository->findOneBy([
                'user' => $user,
                'business' => $business,
            ]);

            if (!$membership) {
                $membership = new BusinessUser();
                $membership->setUser($user);
                $membership->setBusiness($business);
                $membership->setRole($this->resolveRole($user));
                $membership->setIsActive(true);
                $this->entityManager->persist($membership);
                $created++;
            } else {
                $membership->setIsActive(true);
                if ($membership->getRole() === BusinessUser::ROLE_SELLER) {
                    $membership->setRole($this->resolveRole($user));
                }
                $reactivated++;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Membresías creadas: %d. Membresías reactivadas/actualizadas: %d.',
            $created,
            $reactivated
        ));

        return Command::SUCCESS;
    }

    private function resolveRole(User $user): string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_BUSINESS_ADMIN', $roles, true)) {
            return BusinessUser::ROLE_ADMIN;
        }

        return BusinessUser::ROLE_SELLER;
    }
}
