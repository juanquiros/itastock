<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\User;
use App\Form\StockImportType;
use App\Service\StockCsvImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/app/admin/stock', name: 'app_stock_')]
class StockController extends AbstractController
{
    public function __construct(private readonly StockCsvImportService $importService)
    {
    }

    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    public function import(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $form = $this->createForm(StockImportType::class);
        $form->handleRequest($request);

        $results = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            $dryRun = (bool) $form->get('dryRun')->getData();

            $results = $this->importService->import($file, $business, $this->requireUser(), $dryRun);

            $message = sprintf(
                'Importación de stock: %d aplicadas, %d fallidas%s.',
                $results['applied'],
                count($results['failed']),
                $dryRun ? ' (sin aplicar cambios)' : ''
            );

            $this->addFlash('success', $message);
        }

        return $this->render('stock/import.html.twig', [
            'form' => $form,
            'results' => $results,
        ]);
    }

    private function requireBusinessContext(): Business
    {
        $business = $this->getUser()?->getBusiness();

        if (!$business instanceof Business) {
            throw new AccessDeniedException('No se puede ajustar stock sin un comercio asignado.');
        }

        return $business;
    }

    private function requireUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedException('Debés iniciar sesión.');
        }

        return $user;
    }
}
