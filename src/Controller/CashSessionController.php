<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\CashSession;
use App\Repository\CashSessionRepository;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash', name: 'app_cash_')]
class CashSessionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashSessionRepository $cashSessionRepository,
        private readonly PaymentRepository $paymentRepository,
    ) {
    }

    #[Route('', name: 'status', methods: ['GET'])]
    public function status(): Response
    {
        $business = $this->requireBusinessContext();
        $user = $this->requireUser();
        $openSession = $this->cashSessionRepository->findOpenForUser($business, $user);
        $runningTotals = $openSession
            ? $this->paymentRepository->aggregateTotalsByMethod($business, $openSession->getOpenedAt(), new \DateTimeImmutable())
            : [];

        $recentCriteria = ['business' => $business];

        if (!$this->isGranted('ROLE_ADMIN')) {
            $recentCriteria['openedBy'] = $user;
        }

        $recentSessions = $this->cashSessionRepository->findBy(
            $recentCriteria,
            ['openedAt' => 'DESC'],
            5,
        );

        return $this->render('cash/status.html.twig', [
            'openSession' => $openSession,
            'runningTotals' => $runningTotals,
            'recentSessions' => $recentSessions,
        ]);
    }

    #[Route('/open', name: 'open', methods: ['POST'])]
    public function open(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $user = $this->requireUser();

        if (!$this->isCsrfTokenValid('open_cash', (string) $request->request->get('_token'))) {
            throw new AccessDeniedException('Token CSRF inválido.');
        }

        if ($this->cashSessionRepository->findOpenForUser($business, $user)) {
            $this->addFlash('danger', 'Ya tenés una caja abierta.');

            return $this->redirectToRoute('app_cash_status');
        }

        $initialCash = max((float) $request->request->get('initial_cash', 0), 0);

        $cashSession = new CashSession();
        $cashSession->setBusiness($business);
        $cashSession->setOpenedBy($user);
        $cashSession->setInitialCash(number_format($initialCash, 2, '.', ''));
        $cashSession->setTotalsByPaymentMethod([]);

        $this->entityManager->persist($cashSession);
        $this->entityManager->flush();

        $this->addFlash('success', 'Caja abierta.');

        return $this->redirectToRoute('app_cash_status');
    }

    #[Route('/close', name: 'close', methods: ['POST'])]
    public function close(Request $request): Response
    {
        $business = $this->requireBusinessContext();
        $user = $this->requireUser();

        if (!$this->isCsrfTokenValid('close_cash', (string) $request->request->get('_token'))) {
            throw new AccessDeniedException('Token CSRF inválido.');
        }

        $openSession = $this->cashSessionRepository->findOpenForUser($business, $user);

        if ($openSession === null) {
            $this->addFlash('danger', 'No hay una caja abierta para cerrar.');

            return $this->redirectToRoute('app_cash_status');
        }

        $closedAt = new \DateTimeImmutable();
        $totals = $this->paymentRepository->aggregateTotalsByMethod($business, $openSession->getOpenedAt(), $closedAt);
        $finalCash = max((float) $request->request->get('final_cash_counted', 0), 0);

        $openSession->setClosedAt($closedAt);
        $openSession->setFinalCashCounted(number_format($finalCash, 2, '.', ''));
        $openSession->setTotalsByPaymentMethod($totals);

        $this->entityManager->flush();

        $this->addFlash('success', 'Caja cerrada.');

        return $this->redirectToRoute('app_cash_report', ['id' => $openSession->getId()]);
    }

    #[Route('/report/{id}', name: 'report', methods: ['GET'])]
    public function report(CashSession $cashSession): Response
    {
        $business = $this->requireBusinessContext();
        $user = $this->requireUser();

        if ($cashSession->getBusiness() !== $business) {
            throw new AccessDeniedException('Solo podés ver cajas de tu comercio.');
        }

        if (!$this->isGranted('ROLE_ADMIN') && $cashSession->getOpenedBy()?->getId() !== $user->getId()) {
            throw new AccessDeniedException('Solo podés ver tus propias cajas.');
        }

        $totals = $cashSession->isOpen()
            ? $this->paymentRepository->aggregateTotalsByMethod($business, $cashSession->getOpenedAt(), new \DateTimeImmutable())
            : $cashSession->getTotalsByPaymentMethod();

        return $this->render('cash/report.html.twig', [
            'cashSession' => $cashSession,
            'totals' => $totals,
        ]);
    }

    private function requireBusinessContext(): Business
    {
        $business = $this->requireUser()->getBusiness();

        if (!$business instanceof Business) {
            throw new AccessDeniedException('No se puede operar sin un comercio asignado.');
        }

        return $business;
    }

    private function requireUser(): UserInterface
    {
        $user = $this->getUser();

        if ($user === null) {
            throw new AccessDeniedException('Debés iniciar sesión para operar.');
        }

        return $user;
    }
}
