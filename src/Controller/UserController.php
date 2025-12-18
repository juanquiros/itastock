<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[IsGranted('ROLE_ADMIN')]
#[Route('/app/admin/users', name: 'app_user_')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        $business = $this->requireBusinessContext();

        return $this->render('user/index.html.twig', [
            'users' => $userRepository->findBy(['business' => $business]),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $business = $this->requireBusinessContext();

        $user = new User();
        $user->setBusiness($business);

        $form = $this->createForm(UserType::class, $user, [
            'require_password' => true,
            'current_business' => $business,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleUserPersistence($user, $form->get('plainPassword')->getData(), $form->get('role')->getData());

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

        $form = $this->createForm(UserType::class, $user, [
            'require_password' => false,
            'current_business' => $business,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleUserPersistence($user, $form->get('plainPassword')->getData(), $form->get('role')->getData());

            $this->addFlash('success', 'Usuario actualizado.');

            return $this->redirectToRoute('app_user_index');
        }

        return $this->render('user/edit.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, User $user, UserRepository $userRepository): Response
    {
        $business = $this->requireBusinessContext();
        $this->denyIfDifferentBusiness($user, $business);

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'No podés eliminar tu propio usuario.');

            return $this->redirectToRoute('app_user_index');
        }

        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $adminCount = $userRepository->countAdminsByBusiness($business);

                if ($adminCount <= 1) {
                    $this->addFlash('error', 'Debe quedar al menos un administrador en el comercio.');

                    return $this->redirectToRoute('app_user_index');
                }
            }

            $this->entityManager->remove($user);
            $this->entityManager->flush();

            $this->addFlash('success', 'Usuario eliminado.');
        }

        return $this->redirectToRoute('app_user_index');
    }

    private function handleUserPersistence(User $user, ?string $plainPassword, string $selectedRole): void
    {
        $user->setRoles([$selectedRole]);

        if (!empty($plainPassword)) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    private function requireBusinessContext(): Business
    {
        $business = $this->getUser()?->getBusiness();

        if (!$business instanceof Business) {
            throw new AccessDeniedException('No se puede gestionar usuarios sin un comercio asignado.');
        }

        return $business;
    }

    private function denyIfDifferentBusiness(User $user, Business $adminBusiness): void
    {
        if ($user->getBusiness() && $user->getBusiness() !== $adminBusiness) {
            throw new AccessDeniedException('Solo podés gestionar usuarios de tu comercio.');
        }
    }
}
