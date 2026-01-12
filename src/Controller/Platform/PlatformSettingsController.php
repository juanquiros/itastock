<?php

namespace App\Controller\Platform;

use App\Entity\PlatformSettings;
use App\Form\PlatformSettingsType;
use App\Repository\PlatformSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
#[Route('/platform/settings')]
class PlatformSettingsController extends AbstractController
{
    #[Route('/pos', name: 'platform_settings_pos', methods: ['GET', 'POST'])]
    public function pos(
        Request $request,
        PlatformSettingsRepository $platformSettingsRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $settings = $platformSettingsRepository->getOrCreate();
        $form = $this->createForm(PlatformSettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($settings->getId() === null) {
                $entityManager->persist($settings);
            }
            $entityManager->flush();
            $this->addFlash('success', 'Configuración de POS actualizada.');

            return $this->redirectToRoute('platform_settings_pos');
        }

        return $this->render('platform/settings/pos.html.twig', [
            'form' => $form->createView(),
            'settings' => $settings,
        ]);
    }
}
