<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Product;
use App\Entity\StockMovement;
use App\Form\ProductType;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
            $this->handleStockAdjustment($product, (int) $form->get('stockAdjustment')->getData());

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
            $this->handleStockAdjustment($product, (int) $form->get('stockAdjustment')->getData());

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

    private function handleStockAdjustment(Product $product, int $adjustment): void
    {
        if ($adjustment === 0) {
            return;
        }

        $product->adjustStock($adjustment);

        $movement = new StockMovement();
        $movement->setProduct($product);
        $movement->setType(StockMovement::TYPE_ADJUST);
        $movement->setQty($adjustment);
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

    private function denyIfDifferentBusiness(Product $product, Business $business): void
    {
        if ($product->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés gestionar productos de tu comercio.');
        }
    }
}
