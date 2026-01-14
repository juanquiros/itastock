<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\User;
use App\Repository\BusinessRepository;
use App\Repository\BusinessUserRepository;
use App\Security\BusinessContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class BusinessSwitchController extends AbstractController
{
    public function __construct(
        private readonly BusinessUserRepository $businessUserRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly BusinessContext $businessContext,
    ) {
    }

    #[Route('/app/business/switch', name: 'app_business_switch', methods: ['GET', 'POST'])]
    public function switch(Request $request): Response
    {
        $user = $this->requireUser();
        $memberships = $this->businessUserRepository->findActiveMembershipsForUser($user);

        if (count($memberships) === 1) {
            $business = $memberships[0]->getBusiness();
            if ($business instanceof Business) {
                $this->businessContext->setCurrentBusiness($business);
                return $this->redirectToRoute('app_dashboard');
            }
        }

        if ($request->isMethod('POST')) {
            $businessId = (int) $request->request->get('business_id');
            $business = $this->businessRepository->find($businessId);
            if (!$business instanceof Business) {
                throw $this->createNotFoundException();
            }

            $membership = $this->businessUserRepository->findActiveMembership($user, $business);
            if (!$membership) {
                throw new AccessDeniedException('No podés seleccionar este comercio.');
            }

            $this->businessContext->setCurrentBusiness($business);

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('business/switch.html.twig', [
            'memberships' => $memberships,
            'current_business' => $this->businessContext->getCurrentBusiness($user),
        ]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Necesitás iniciar sesión.');
        }

        return $user;
    }
}
