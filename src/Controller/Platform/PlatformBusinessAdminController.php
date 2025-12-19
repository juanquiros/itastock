<?php

namespace App\Controller\Platform;

use App\Entity\Business;
use App\Entity\User;
use App\Form\BusinessAdminUserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
class PlatformBusinessAdminController extends AbstractController
{
    #[Route('/platform/businesses/{id}/admins/new', name: 'platform_business_admin_new', methods: ['GET', 'POST'])]
    public function new(
        Business $business,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $user = new User();
        $user->setBusiness($business);

        $form = $this->createForm(BusinessAdminUserType::class, $user);
        $form->handleRequest($request);
        $temporaryPassword = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $temporaryPassword = (string) $form->get('plainPassword')->getData();
            $user->setRoles(['ROLE_BUSINESS_ADMIN']);
            $hashed = $passwordHasher->hashPassword($user, $temporaryPassword);
            $user->setPassword($hashed);
            $entityManager->persist($user);
            $entityManager->flush();

            return $this->render('platform/business/admin_created.html.twig', [
                'user' => $user,
                'temporaryPassword' => $temporaryPassword,
            ]);
        }

        return $this->render('platform/business/admin_form.html.twig', [
            'form' => $form->createView(),
            'business' => $business,
        ]);
    }
}
