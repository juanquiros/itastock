<?php

namespace App\Controller;

use App\Entity\Business;
use App\Repository\SaleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/app/admin/sales', name: 'app_admin_sales_')]
class SalesAdminController extends AbstractController
{
    public function __construct(private readonly SaleRepository $saleRepository)
    {
    }

    #[Route('/export.{_format}', name: 'export', defaults: ['_format' => 'html'], requirements: ['_format' => 'html|csv'], methods: ['GET'])]
    public function export(Request $request): Response
    {
        $business = $this->requireBusinessContext();

        $fromInput = $request->query->get('from');
        $toInput = $request->query->get('to');
        $errors = [];

        $fromDate = $fromInput ? \DateTimeImmutable::createFromFormat('Y-m-d', $fromInput) : null;
        $toDate = $toInput ? \DateTimeImmutable::createFromFormat('Y-m-d', $toInput) : null;

        if ($fromInput !== null && $fromDate === false) {
            $errors[] = 'Fecha desde inválida.';
        }

        if ($toInput !== null && $toDate === false) {
            $errors[] = 'Fecha hasta inválida.';
        }

        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            $errors[] = 'La fecha desde debe ser menor o igual a la fecha hasta.';
        }

        if ($request->getRequestFormat() !== 'csv' || $fromDate === null || $toDate === null || $errors !== []) {
            return $this->render('sale/export.html.twig', [
                'errors' => $errors,
                'filters' => [
                    'from' => $fromInput,
                    'to' => $toInput,
                ],
            ]);
        }

        $fromDate = $fromDate->setTime(0, 0, 0);
        $toDate = $toDate->setTime(23, 59, 59);

        $rows = $this->saleRepository->findForExport($business, $fromDate, $toDate);

        $response = new StreamedResponse();
        $response->setCallback(static function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['date', 'saleId', 'sellerEmail', 'customerName', 'paymentMethod', 'total', 'itemsCount'], ';');

            foreach ($rows as $row) {
                $createdAt = $row['createdAt'];
                $dateValue = $createdAt instanceof \DateTimeInterface ? $createdAt->format('Y-m-d H:i:s') : '';

                fputcsv($handle, [
                    $dateValue,
                    $row['saleId'],
                    $row['sellerEmail'],
                    $row['customerName'],
                    $row['paymentMethod'],
                    number_format((float) $row['total'], 2, '.', ''),
                    $row['itemsCount'],
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="sales.csv"');

        return $response;
    }

    private function requireBusinessContext(): Business
    {
        $business = $this->getUser()?->getBusiness();

        if (!$business instanceof Business) {
            throw new AccessDeniedException('No se puede exportar ventas sin un comercio asignado.');
        }

        return $business;
    }
}
