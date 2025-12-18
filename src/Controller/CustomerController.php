<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Customer;
use App\Form\CustomerType;
use App\Repository\CustomerRepository;
use App\Repository\PriceListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/app/admin/customers', name: 'app_customer_')]
class CustomerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PriceListRepository $priceListRepository,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, CustomerRepository $customerRepository): Response
    {
        $business = $this->requireBusinessContext();
        $query = $request->query->get('q');

        return $this->render('customer/index.html.twig', [
            'customers' => $customerRepository->searchByBusiness($business, $query),
            'query' => $query,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $business = $this->requireBusinessContext();

        $customer = new Customer();
        $customer->setBusiness($business);

        $form = $this->createForm(CustomerType::class, $customer, [
            'price_lists' => $this->priceListRepository->findActiveForBusiness($business),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($customer);
            $this->entityManager->flush();

            $this->addFlash('success', 'Cliente creado.');

            return $this->redirectToRoute('app_customer_index');
        }

        return $this->render('customer/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Customer $customer): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($customer, $business);

        $form = $this->createForm(CustomerType::class, $customer, [
            'price_lists' => $this->priceListRepository->findActiveForBusiness($business),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Cliente actualizado.');

            return $this->redirectToRoute('app_customer_index');
        }

        return $this->render('customer/edit.html.twig', [
            'form' => $form,
            'customer' => $customer,
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Customer $customer): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($customer, $business);

        if ($this->isCsrfTokenValid('delete'.$customer->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($customer);
            $this->entityManager->flush();

            $this->addFlash('success', 'Cliente eliminado.');
        }

        return $this->redirectToRoute('app_customer_index');
    }

    private function requireBusinessContext(): Business
    {
        $business = $this->getUser()?->getBusiness();

        if (!$business instanceof Business) {
            throw new AccessDeniedException('No se puede gestionar clientes sin un comercio asignado.');
        }

        return $business;
    }

    private function denyIfDifferentBusiness(Customer $customer, Business $business): void
    {
        if ($customer->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés gestionar clientes de tu comercio.');
        }
    }
}
