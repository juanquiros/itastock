<?php

namespace App\Controller;

use App\Entity\Business;
use App\Repository\CustomerRepository;
use App\Service\CustomerAccountService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/app/admin/reports', name: 'app_reports_')]
class ReportController extends AbstractController
{
    public function __construct(
        private readonly CustomerAccountService $customerAccountService,
        private readonly CustomerRepository $customerRepository,
    ) {
    }

    #[Route('/debtors', name: 'debtors', methods: ['GET'])]
    public function debtors(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $minBalance = max(0, (float) $request->query->get('min_balance', 0));

        $rows = $this->customerAccountService->findDebtors($business, $minBalance);
        $ids = array_map(static fn (array $row) => (int) $row['customerId'], $rows);
        $customers = $ids ? $this->customerRepository->findBy(['id' => $ids]) : [];

        $byId = [];
        foreach ($customers as $customer) {
            $byId[$customer->getId()] = $customer;
        }

        $report = [];
        foreach ($rows as $row) {
            $customer = $byId[$row['customerId']] ?? null;

            if ($customer === null) {
                continue;
            }

            $report[] = [
                'customer' => $customer,
                'balance' => number_format((float) $row['balance'], 2, '.', ''),
                'lastMovement' => $row['lastMovement'] ? new \DateTimeImmutable($row['lastMovement']) : null,
            ];
        }

        usort($report, static fn ($a, $b) => $b['balance'] <=> $a['balance']);

        return $this->render('reports/debtors.html.twig', [
            'items' => $report,
            'minBalance' => $minBalance,
        ]);
    }

    private function requireBusinessContext(): Business
    {
        $business = $this->getUser()?->getBusiness();

        if (!$business instanceof Business) {
            throw new AccessDeniedException('No se puede operar sin un comercio asignado.');
        }

        return $business;
    }
}
