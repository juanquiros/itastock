<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Product;
use App\Entity\StockMovement;
use App\Form\ProductType;
use App\Form\ProductImportType;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Service\ProductCsvImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/app/admin/products', name: 'app_product_')]
class ProductController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ProductRepository $productRepository): Response
    {
        $business = $this->requireBusinessContext();

        return $this->render('product/index.html.twig', [
            'products' => $productRepository->findBy(['business' => $business], ['name' => 'ASC']),
        ]);
    }

    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    public function export(ProductRepository $productRepository): Response
    {
        $business = $this->requireBusinessContext();

        $products = $productRepository->findBy(['business' => $business], ['name' => 'ASC']);

        $response = new StreamedResponse();
        $response->setCallback(static function () use ($products): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['sku', 'barcode', 'name', 'cost', 'basePrice', 'stockMin', 'isActive'], ';');

            foreach ($products as $product) {
                fputcsv($handle, [
                    $product->getSku(),
                    $product->getBarcode(),
                    $product->getName(),
                    number_format((float) $product->getCost(), 2, '.', ''),
                    number_format((float) $product->getBasePrice(), 2, '.', ''),
                    $product->getStockMin(),
                    $product->isActive() ? '1' : '0',
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="products.csv"');

        return $response;
    }

    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    public function import(Request $request, ProductCsvImportService $importService): Response
    {
        $business = $this->requireBusinessContext();
        $form = $this->createForm(ProductImportType::class);
        $form->handleRequest($request);

        $results = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            $dryRun = (bool) $form->get('dryRun')->getData();

            $results = $importService->import($file, $business, $this->requireUser(), $dryRun);

            $message = sprintf(
                'Importación finalizada: %d creados, %d actualizados, %d fallidos%s.',
                $results['created'],
                $results['updated'],
                count($results['failed']),
                $dryRun ? ' (sin aplicar cambios)' : ''
            );

            $this->addFlash('success', $message);
        }

        return $this->render('product/import.html.twig', [
            'form' => $form,
            'results' => $results,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $business = $this->requireBusinessContext();

        $product = new Product();
        $product->setBusiness($business);

        $form = $this->createForm(ProductType::class, $product, [
            'current_business' => $business,
            'show_stock' => true,
            'current_stock' => $product->getStock(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($product);
            $this->handleStockAdjustment($product, (string) $form->get('stockAdjustment')->getData());

            $this->entityManager->flush();

            $this->addFlash('success', 'Producto creado.');

            return $this->redirectToRoute('app_product_index');
        }

        return $this->render('product/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Product $product): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($product, $business);

        $form = $this->createForm(ProductType::class, $product, [
            'current_business' => $business,
            'show_stock' => true,
            'current_stock' => $product->getStock(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleStockAdjustment($product, (string) $form->get('stockAdjustment')->getData());

            $this->entityManager->flush();

            $this->addFlash('success', 'Producto actualizado.');

            return $this->redirectToRoute('app_product_index');
        }

        return $this->render('product/edit.html.twig', [
            'form' => $form,
            'product' => $product,
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Product $product): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($product, $business);

        if ($this->isCsrfTokenValid('delete'.$product->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($product);
            $this->entityManager->flush();

            $this->addFlash('success', 'Producto eliminado.');
        }

        return $this->redirectToRoute('app_product_index');
    }

    private function handleStockAdjustment(Product $product, string $adjustment): void
    {
        $normalized = $adjustment === '' ? '0' : $adjustment;

        if (!preg_match('/^-?\d+(?:\.\d{1,3})?$/', $normalized)) {
            $this->addFlash('danger', 'Ingresá un número válido para el ajuste de stock.');

            return;
        }

        $normalized = bcadd($normalized, '0', 3);

        if (bccomp($normalized, '0', 3) === 0) {
            return;
        }

        $newStock = bcadd($product->getStock(), $normalized, 3);
        if (bccomp($newStock, '0', 3) < 0) {
            $this->addFlash('danger', 'El ajuste dejaría el stock en negativo.');

            return;
        }

        $product->adjustStock($normalized);

        $movement = new StockMovement();
        $movement->setProduct($product);
        $movement->setType(StockMovement::TYPE_ADJUST);
        $movement->setQty($normalized);
        $movement->setReference('Ajuste de stock');
        $movement->setCreatedBy($this->getUser());

        $this->entityManager->persist($movement);
    }

    private function requireBusinessContext(): Business
    {
        $business = $this->getUser()?->getBusiness();

        if (!$business instanceof Business) {
            throw new AccessDeniedException('No se puede gestionar productos sin un comercio asignado.');
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

    private function denyIfDifferentBusiness(Product $product, Business $business): void
    {
        if ($product->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés gestionar productos de tu comercio.');
        }
    }
}
