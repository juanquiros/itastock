<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\Customer;
use App\Entity\User;
use App\Form\CustomerType;
use App\Repository\CustomerRepository;
use App\Repository\PriceListRepository;
use App\Service\CustomerAccountService;
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
        private readonly CustomerAccountService $customerAccountService,
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

    #[Route('/{id}/account', name: 'account', methods: ['GET'])]
    public function account(Request $request, Customer $customer): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($customer, $business);

        $from = $request->query->get('from');
        $to = $request->query->get('to');
        $type = $request->query->get('type');

        $fromDate = $from ? new \DateTimeImmutable($from) : null;
        $toDate = $to ? new \DateTimeImmutable($to.' 23:59:59') : null;
        $typeFilter = $type ?: null;

        return $this->render('customer/account.html.twig', [
            'customer' => $customer,
            'balance' => $this->customerAccountService->getBalance($customer),
            'movements' => $this->customerAccountService->getMovements($customer, $fromDate, $toDate, $typeFilter),
            'filters' => [
                'from' => $from,
                'to' => $to,
                'type' => $typeFilter,
            ],
        ]);
    }

    #[Route('/{id}/collect', name: 'collect', methods: ['GET', 'POST'])]
    public function collect(Request $request, Customer $customer): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($customer, $business);

        if (!$customer->isActive()) {
            $this->addFlash('danger', 'No podés registrar pagos para un cliente inactivo.');

            return $this->redirectToRoute('app_customer_account', ['id' => $customer->getId()]);
        }

        $balance = $this->customerAccountService->getBalance($customer);
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedException('Debés iniciar sesión.');
        }

        if ($request->isMethod('POST')) {
            $amount = (string) $request->request->get('amount', '0');
            $method = (string) $request->request->get('method', 'CASH');
            $note = (string) $request->request->get('note', '');
            $allowed = ['CASH', 'TRANSFER', 'CARD'];

            if (!in_array($method, $allowed, true)) {
                $this->addFlash('danger', 'Elegí un medio de cobro válido.');

                return $this->redirectToRoute('app_customer_collect', ['id' => $customer->getId()]);
            }

            try {
                $this->customerAccountService->addCreditPayment($customer, $amount, $method, $note, $user);
                $this->entityManager->flush();

                $this->addFlash('success', 'Pago registrado en la cuenta corriente.');

                return $this->redirectToRoute('app_customer_account', ['id' => $customer->getId()]);
            } catch (AccessDeniedException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('app_customer_collect', ['id' => $customer->getId()]);
            }
        }

        return $this->render('customer/collect.html.twig', [
            'customer' => $customer,
            'balance' => $balance,
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
