<?php

namespace App\Controller\Platform;

use App\Entity\Business;
use App\Form\BusinessType;
use App\Repository\BusinessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
#[Route('/platform/businesses')]
class PlatformBusinessController extends AbstractController
{
    #[Route('', name: 'platform_business_index', methods: ['GET'])]
    public function index(BusinessRepository $businessRepository): Response
    {
        return $this->render('platform/business/index.html.twig', [
            'businesses' => $businessRepository->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'platform_business_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $business = new Business();
        $form = $this->createForm(BusinessType::class, $business);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($business);
            $entityManager->flush();
            $this->addFlash('success', 'Comercio creado.');

            return $this->redirectToRoute('platform_business_index');
        }

        return $this->render('platform/business/form.html.twig', [
            'form' => $form->createView(),
            'business' => $business,
        ]);
    }

    #[Route('/{id}', name: 'platform_business_show', methods: ['GET'])]
    public function show(Business $business): Response
    {
        return $this->render('platform/business/show.html.twig', [
            'business' => $business,
        ]);
    }

    #[Route('/{id}/edit', name: 'platform_business_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Business $business, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(BusinessType::class, $business);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Comercio actualizado.');

            return $this->redirectToRoute('platform_business_index');
        }

        return $this->render('platform/business/form.html.twig', [
            'form' => $form->createView(),
            'business' => $business,
        ]);
    }
}
