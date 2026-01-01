<?php

namespace App\Controller\Platform;

use App\Repository\EmailNotificationLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLATFORM_ADMIN')]
#[Route('/platform/emails')]
class PlatformEmailLogController extends AbstractController
{
    #[Route('', name: 'platform_email_logs', methods: ['GET'])]
    public function index(Request $request, EmailNotificationLogRepository $repository): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        $result = $repository->findPlatformLogs($query, $page, $limit);
        $total = $result['total'];
        $pages = (int) ceil($total / $limit);

        return $this->render('platform/email_logs/index.html.twig', [
            'logs' => $result['items'],
            'query' => $query,
            'page' => $page,
            'pages' => max(1, $pages),
            'total' => $total,
        ]);
    }
}
