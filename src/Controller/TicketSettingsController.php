<?php

namespace App\Controller;

use App\Entity\Business;
use App\Form\BusinessTicketSettingsType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/app/settings', name: 'app_settings_')]
class TicketSettingsController extends AbstractController
{
    #[Route('/ticket', name: 'ticket', methods: ['GET', 'POST'])]
    public function ticket(Request $request, EntityManagerInterface $entityManager): Response
    {
        $business = $this->requireBusinessContext();
        $form = $this->createForm(BusinessTicketSettingsType::class, $business);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Configuración del ticket actualizada.');

            return $this->redirectToRoute('app_settings_ticket');
        }

        return $this->render('settings/ticket.html.twig', [
            'form' => $form->createView(),
            'business' => $business,
        ]);
    }

    private function requireBusinessContext(): Business
    {
        $business = $this->getUser()?->getBusiness();

        if (!$business instanceof Business) {
            throw new AccessDeniedException('No se puede configurar el ticket sin un comercio asignado.');
        }

        return $business;
    }
}
