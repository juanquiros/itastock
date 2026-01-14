<?php

namespace App\Controller\Platform;

use App\Entity\Business;
use App\Repository\BusinessUserRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
class PlatformBusinessUserController extends AbstractController
{
    #[Route('/platform/businesses/{id}/users', name: 'platform_business_users', methods: ['GET'])]
    public function index(Business $business, BusinessUserRepository $businessUserRepository): Response
    {
        return $this->render('platform/business/users.html.twig', [
            'business' => $business,
            'memberships' => $businessUserRepository->findBy(['business' => $business], ['createdAt' => 'ASC']),
        ]);
    }

    #[Route('/platform/businesses/{businessId}/users/{userId}/toggle', name: 'platform_business_user_toggle', methods: ['POST'])]
    public function toggle(
        int $businessId,
        int $userId,
        Request $request,
        UserRepository $userRepository,
        BusinessUserRepository $businessUserRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $business = $userRepository->getEntityManager()->getRepository(Business::class)->find($businessId);
        if (!$business) {
            throw $this->createNotFoundException();
        }

        $user = $userRepository->find($userId);
        $membership = $user instanceof \App\Entity\User
            ? $businessUserRepository->findOneBy(['business' => $business, 'user' => $user])
            : null;
        if (!$membership) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('toggle_user_'.$user->getId(), (string) $request->request->get('_token'))) {
            $membership->setIsActive(!$membership->isActive());
            $entityManager->flush();
            $this->addFlash('success', 'Usuario actualizado.');
        }

        return $this->redirectToRoute('platform_business_users', ['id' => $businessId]);
    }
}
