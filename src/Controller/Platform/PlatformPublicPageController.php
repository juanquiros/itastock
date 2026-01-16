<?php

namespace App\Controller\Platform;

use App\Entity\PublicPage;
use App\Form\PublicPageType;
use App\Repository\PublicPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
#[Route('/platform/pages')]
class PlatformPublicPageController extends AbstractController
{
    #[Route('', name: 'platform_pages_index', methods: ['GET'])]
    public function index(PublicPageRepository $publicPageRepository): Response
    {
        return $this->render('platform/pages/index.html.twig', [
            'pages' => $publicPageRepository->findBy([], ['slug' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'platform_pages_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $page = new PublicPage();
        $form = $this->createForm(PublicPageType::class, $page);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->syncMetaImage($request, $form, $page);
            $entityManager->persist($page);
            $entityManager->flush();
            $this->addFlash('success', 'Página creada correctamente.');

            return $this->redirectToRoute('platform_pages_index');
        }

        return $this->render('platform/pages/form.html.twig', [
            'form' => $form->createView(),
            'page' => $page,
        ]);
    }

    #[Route('/{id}', name: 'platform_pages_show', methods: ['GET'])]
    public function show(PublicPage $page): Response
    {
        return $this->render('platform/pages/show.html.twig', [
            'page' => $page,
        ]);
    }

    #[Route('/{id}/edit', name: 'platform_pages_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, PublicPage $page, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(PublicPageType::class, $page);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->syncMetaImage($request, $form, $page);
            $entityManager->flush();
            $this->addFlash('success', 'Página actualizada.');

            return $this->redirectToRoute('platform_pages_index');
        }

        return $this->render('platform/pages/form.html.twig', [
            'form' => $form->createView(),
            'page' => $page,
        ]);
    }

    private function syncMetaImage(Request $request, FormInterface $form, PublicPage $page): void
    {
        $formName = $form->getName();
        $rawData = $request->request->all($formName);
        $metaImagePath = $rawData['metaImagePath'] ?? $page->getMetaImagePath();
        $uploadedFile = $request->files->get($formName)['metaImageFile'] ?? null;

        if (($metaImagePath === null || $metaImagePath === '') && $uploadedFile instanceof UploadedFile) {
            $contents = file_get_contents($uploadedFile->getPathname());
            if ($contents !== false) {
                $mimeType = $uploadedFile->getMimeType() ?: 'image/png';
                $metaImagePath = sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));
            }
        }

        $page->setMetaImagePath($metaImagePath ?: null);
    }
}
