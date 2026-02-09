<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\BusinessUser;
use App\Entity\User;
use App\Form\UserType;
use App\Repository\BusinessUserRepository;
use App\Repository\UserRepository;
use App\Security\BusinessContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[IsGranted('BUSINESS_ADMIN')]
#[Route('/app/admin/users', name: 'app_user_')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly BusinessUserRepository $businessUserRepository,
        private readonly BusinessContext $businessContext,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $business = $this->requireBusinessContext();

        return $this->render('user/index.html.twig', [
            'memberships' => $this->businessUserRepository->findBy(
                ['business' => $business, 'isActive' => true],
                ['createdAt' => 'ASC']
            ),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserRepository $userRepository): Response
    {
        $business = $this->requireBusinessContext();
        $user = new User();
        $requirePassword = true;
        $currentRole = BusinessUser::ROLE_SELLER;
        $membership = null;

        if ($request->isMethod('POST')) {
            $formData = $request->request->all('user');
            $email = mb_strtolower((string) ($formData['email'] ?? ''));
            if ($email !== '') {
                $existing = $userRepository->findOneBy(['email' => $email]);
                if ($existing instanceof User) {
                    $user = $existing;
                    $requirePassword = false;
                    $membership = $this->businessUserRepository->findActiveMembership($user, $business);
                    if ($membership) {
                        $currentRole = $membership->getRole();
                    }
                } else {
                    $user->setBusiness($business);
                }
            }
        } else {
            $user->setBusiness($business);
        }

        $form = $this->createForm(UserType::class, $user, [
            'require_password' => $requirePassword,
            'current_role' => $currentRole,
            'membership' => $membership,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleUserPersistence(
                $user,
                $business,
                $form->get('plainPassword')->getData(),
                (string) $form->get('role')->getData(),
                $form->get('arcaEnabledForThisCashier')->getData(),
                (string) $form->get('arcaMode')->getData(),
                $form->get('arcaPosNumber')->getData(),
                $form->get('arcaAutoIssueInvoice')->getData(),
            );

            $this->addFlash('success', 'Usuario creado correctamente.');

            return $this->redirectToRoute('app_user_index');
        }

        return $this->render('user/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user): Response
    {
        $business = $this->requireBusinessContext();

        $this->denyIfDifferentBusiness($user, $business);
        $membership = $this->businessUserRepository->findActiveMembership($user, $business);
        $currentRole = $membership?->getRole() ?? BusinessUser::ROLE_SELLER;

        $form = $this->createForm(UserType::class, $user, [
            'require_password' => false,
            'current_role' => $currentRole,
            'membership' => $membership,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleUserPersistence(
                $user,
                $business,
                $form->get('plainPassword')->getData(),
                (string) $form->get('role')->getData(),
                $form->get('arcaEnabledForThisCashier')->getData(),
                (string) $form->get('arcaMode')->getData(),
                $form->get('arcaPosNumber')->getData(),
                $form->get('arcaAutoIssueInvoice')->getData(),
            );

            $this->addFlash('success', 'Usuario actualizado.');

            return $this->redirectToRoute('app_user_index');
        }

        return $this->render('user/edit.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, User $user): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($user, $business);

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'No podés eliminar tu propio usuario.');

            return $this->redirectToRoute('app_user_index');
        }

        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            $membership = $this->businessUserRepository->findActiveMembership($user, $business);
            if ($membership && in_array($membership->getRole(), [BusinessUser::ROLE_OWNER, BusinessUser::ROLE_ADMIN], true)) {
                $adminCount = $this->businessUserRepository->countActiveAdminsForBusiness($business);

                if ($adminCount <= 1) {
                    $this->addFlash('error', 'Debe quedar al menos un administrador en el comercio.');

                    return $this->redirectToRoute('app_user_index');
                }
            }

            if ($membership) {
                $membership->setIsActive(false);
            }
            $this->entityManager->flush();

            $this->addFlash('success', 'Usuario eliminado.');
        }

        return $this->redirectToRoute('app_user_index');
    }

    private function handleUserPersistence(
        User $user,
        Business $business,
        ?string $plainPassword,
        string $selectedRole,
        mixed $arcaEnabledForThisCashier,
        string $arcaMode,
        mixed $arcaPosNumber,
        mixed $arcaAutoIssueInvoice
    ): void {
        $selectedRole = match ($selectedRole) {
            BusinessUser::ROLE_OWNER, BusinessUser::ROLE_ADMIN, BusinessUser::ROLE_SELLER, BusinessUser::ROLE_READONLY => $selectedRole,
            default => BusinessUser::ROLE_SELLER,
        };

        if ($selectedRole === BusinessUser::ROLE_ADMIN && $user->getPosNumber() === null) {
            $user->setPosNumber(1);
        }

        if (!empty($plainPassword)) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);
        }

        if ($user->getBusiness() === null) {
            $user->setBusiness($business);
        }

        $membership = $this->businessUserRepository->findOneBy([
            'user' => $user,
            'business' => $business,
        ]);

        if (!$membership) {
            $membership = new BusinessUser();
            $membership->setBusiness($business);
            $membership->setUser($user);
            $this->entityManager->persist($membership);
        }

        $membership->setRole($selectedRole);
        $membership->setIsActive(true);
        $membership->setArcaEnabledForThisCashier((bool) $arcaEnabledForThisCashier);
        $membership->setArcaMode($arcaMode ?: 'REMITO_ONLY');
        $membership->setArcaPosNumber($arcaPosNumber !== null ? (int) $arcaPosNumber : null);
        $membership->setArcaAutoIssueInvoice($arcaMode === 'INVOICE' && (bool) $arcaAutoIssueInvoice);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    private function requireBusinessContext(): Business
    {
        return $this->businessContext->requireCurrentBusiness();
    }

    private function denyIfDifferentBusiness(User $user, Business $adminBusiness): void
    {
        $membership = $this->businessUserRepository->findActiveMembership($user, $adminBusiness);
        if (!$membership) {
            throw new AccessDeniedException('Solo podés gestionar usuarios de tu comercio.');
        }
    }
}
