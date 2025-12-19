<?php

namespace App\Controller\Platform;

use App\Repository\LeadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
#[Route('/platform/leads')]
class PlatformLeadController extends AbstractController
{
    #[Route('', name: 'platform_leads_index', methods: ['GET', 'POST'])]
    public function index(Request $request, LeadRepository $leadRepository, EntityManagerInterface $entityManager): Response
    {
        $email = $request->query->get('email');
        $from = $request->query->get('from');
        $to = $request->query->get('to');
        $archive = $request->request->get('archive');

        if ($archive) {
            $lead = $leadRepository->find($archive);
            if ($lead) {
                $lead->setIsArchived(true);
                $entityManager->flush();
                $this->addFlash('success', 'Lead archivado.');
            }

            return $this->redirectToRoute('platform_leads_index', $request->query->all());
        }

        $qb = $leadRepository->createQueryBuilder('l')->orderBy('l.createdAt', 'DESC');
        if ($email) {
            $qb->andWhere('l.email LIKE :email')->setParameter('email', '%'.$email.'%');
        }
        if ($from) {
            $qb->andWhere('l.createdAt >= :from')->setParameter('from', new \DateTimeImmutable($from));
        }
        if ($to) {
            $qb->andWhere('l.createdAt <= :to')->setParameter('to', new \DateTimeImmutable($to.' 23:59:59'));
        }
        $leads = $qb->getQuery()->getResult();

        return $this->render('platform/leads/index.html.twig', [
            'leads' => $leads,
            'filters' => ['email' => $email, 'from' => $from, 'to' => $to],
        ]);
    }

    #[Route('/{id}', name: 'platform_leads_show', methods: ['GET'])]
    public function show(int $id, LeadRepository $leadRepository): Response
    {
        $lead = $leadRepository->find($id);
        if (!$lead) {
            throw $this->createNotFoundException();
        }

        return $this->render('platform/leads/show.html.twig', [
            'lead' => $lead,
        ]);
    }
}
