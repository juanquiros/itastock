<?php

namespace App\Twig;

use App\Entity\Business;
use App\Entity\User;
use App\Security\BusinessContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class BusinessContextExtension extends AbstractExtension
{
    public function __construct(private readonly BusinessContext $businessContext)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_business', [$this, 'getCurrentBusiness']),
        ];
    }

    public function getCurrentBusiness(?User $user = null): ?Business
    {
        return $this->businessContext->getCurrentBusiness($user);
    }
}
