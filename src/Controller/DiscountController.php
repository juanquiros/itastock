<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Discount;
use App\Form\DiscountType;
use App\Repository\DiscountRepository;
use App\Security\BusinessContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/discounts', name: 'app_discount_')]
class DiscountController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BusinessContext $businessContext,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(DiscountRepository $discountRepository): Response
    {
        $business = $this->requireBusinessContext();

        return $this->render('discount/index.html.twig', [
            'discounts' => $discountRepository->findBy(['business' => $business], ['priority' => 'DESC', 'name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $business = $this->requireBusinessContext();

        $discount = new Discount();
        $discount->setBusiness($business);

        $form = $this->createForm(DiscountType::class, $discount, [
            'business' => $business,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($discount);
            $this->entityManager->flush();

            $this->addFlash('success', 'Descuento creado.');

            return $this->redirectToRoute('app_discount_index');
        }

        return $this->render('discount/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Discount $discount): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($discount, $business);

        $form = $this->createForm(DiscountType::class, $discount, [
            'business' => $business,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Descuento actualizado.');

            return $this->redirectToRoute('app_discount_index');
        }

        return $this->render('discount/edit.html.twig', [
            'form' => $form,
            'discount' => $discount,
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Discount $discount): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($discount, $business);

        if ($this->isCsrfTokenValid('delete'.$discount->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($discount);
            $this->entityManager->flush();

            $this->addFlash('success', 'Descuento eliminado.');
        }

        return $this->redirectToRoute('app_discount_index');
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    private function denyIfDifferentBusiness(Discount $discount, Business $business): void
    {
        if ($discount->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés gestionar descuentos de tu comercio.');
        }
    }
}
