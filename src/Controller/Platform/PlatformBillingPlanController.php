<?php

namespace App\Controller\Platform;

use App\Entity\BillingPlan;
use App\Exception\MercadoPagoApiException;
use App\Form\BillingPlanType;
use App\Repository\BillingPlanRepository;
use App\Service\MercadoPagoClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
#[Route('/platform/billing-plans')]
class PlatformBillingPlanController extends AbstractController
{
    #[Route('', name: 'platform_billing_plans_index', methods: ['GET'])]
    public function index(BillingPlanRepository $billingPlanRepository): Response
    {
        return $this->render('platform/billing_plans/index.html.twig', [
            'plans' => $billingPlanRepository->findBy([], ['isActive' => 'DESC', 'name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'platform_billing_plans_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        MercadoPagoClient $mercadoPagoClient,
        UrlGeneratorInterface $urlGenerator,
    ): Response {
        $plan = new BillingPlan();
        $form = $this->createForm(BillingPlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($plan->isActive()) {
                try {
                    $response = $mercadoPagoClient->createPreapprovalPlan([
                        'reason' => $plan->getName(),
                        'back_url' => $urlGenerator->generate('app_billing_return', [], UrlGeneratorInterface::ABSOLUTE_URL),
                        'auto_recurring' => [
                            'frequency' => $plan->getFrequency(),
                            'frequency_type' => $plan->getFrequencyType(),
                            'transaction_amount' => (float) $plan->getPrice(),
                            'currency_id' => $plan->getCurrency(),
                        ],
                    ]);

                    if (!isset($response['id'])) {
                        throw new MercadoPagoApiException(0, 'Respuesta sin ID de preapproval plan.', null);
                    }

                    $plan->setMpPreapprovalPlanId((string) $response['id']);
                } catch (MercadoPagoApiException $exception) {
                    $plan->setIsActive(false);
                    $this->addFlash('danger', sprintf(
                        'Error al sincronizar con Mercado Pago: %s. El plan quedó inactivo.',
                        $exception->getMessage()
                    ));
                }
            }

            $entityManager->persist($plan);
            $entityManager->flush();

            if ($plan->isActive() && $plan->getMpPreapprovalPlanId() !== null) {
                $this->addFlash('success', 'Plan creado y sincronizado con Mercado Pago.');
            } else {
                $this->addFlash('success', 'Plan creado.');
            }

            return $this->redirectToRoute('platform_billing_plans_index');
        }

        return $this->render('platform/billing_plans/form.html.twig', [
            'form' => $form->createView(),
            'plan' => $plan,
        ]);
    }

    #[Route('/{id}/edit', name: 'platform_billing_plans_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, BillingPlan $plan, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(BillingPlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Plan actualizado.');

            return $this->redirectToRoute('platform_billing_plans_index');
        }

        return $this->render('platform/billing_plans/form.html.twig', [
            'form' => $form->createView(),
            'plan' => $plan,
        ]);
    }

    #[Route('/{id}/toggle', name: 'platform_billing_plans_toggle', methods: ['POST'])]
    public function toggle(
        BillingPlan $plan,
        Request $request,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        if ($this->isCsrfTokenValid('toggle_billing_plan_'.$plan->getId(), (string) $request->request->get('_token'))) {
            $plan->setIsActive(!$plan->isActive());
            $entityManager->flush();
            $this->addFlash('success', 'Plan actualizado.');
        }

        return $this->redirectToRoute('platform_billing_plans_index');
    }
}
