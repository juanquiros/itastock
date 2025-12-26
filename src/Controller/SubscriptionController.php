<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\SubscriptionAccessResolver;
use App\Service\SubscriptionContext;

class SubscriptionController extends AbstractController
{
    #[Route('/app/subscription/blocked', name: 'app_subscription_blocked', methods: ['GET'])]
    public function blocked(
        SubscriptionContext $subscriptionContext,
        SubscriptionAccessResolver $accessResolver,
    ): Response
    {
        $subscription = $subscriptionContext->getCurrentSubscription($this->getUser());
        $access = $accessResolver->resolve($subscription);

        return $this->render('subscription/blocked.html.twig', [
            'access' => $access,
        ]);
    }
}
