<?php

namespace App\Controller\Platform;

use App\Entity\Plan;
use App\Form\PlanType;
use App\Repository\PlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
#[Route('/platform/plans')]
class PlatformPlanController extends AbstractController
{
    #[Route('', name: 'platform_plans_index', methods: ['GET'])]
    public function index(PlanRepository $planRepository): Response
    {
        return $this->render('platform/plans/index.html.twig', [
            'plans' => $planRepository->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'platform_plans_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $plan = new Plan();
        $form = $this->createForm(PlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($plan);
            $entityManager->flush();
            $this->addFlash('success', 'Plan creado.');

            return $this->redirectToRoute('platform_plans_index');
        }

        return $this->render('platform/plans/form.html.twig', [
            'form' => $form->createView(),
            'plan' => $plan,
        ]);
    }

    #[Route('/{id}', name: 'platform_plans_show', methods: ['GET'])]
    public function show(Plan $plan): Response
    {
        return $this->render('platform/plans/show.html.twig', [
            'plan' => $plan,
        ]);
    }

    #[Route('/{id}/edit', name: 'platform_plans_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Plan $plan, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(PlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Plan actualizado.');

            return $this->redirectToRoute('platform_plans_index');
        }

        return $this->render('platform/plans/form.html.twig', [
            'form' => $form->createView(),
            'plan' => $plan,
        ]);
    }
}
