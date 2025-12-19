<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SubscriptionController extends AbstractController
{
    #[Route('/app/subscription/blocked', name: 'app_subscription_blocked', methods: ['GET'])]
    public function blocked(): Response
    {
        return $this->render('subscription/blocked.html.twig');
    }
}
