<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\BusinessUserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

class BusinessAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly BusinessContext $businessContext,
        private readonly BusinessUserRepository $businessUserRepository,
        private readonly RouterInterface $router,
        private readonly Security $security,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        if ($request->attributes->get('_route') === 'app_business_switch') {
            return null;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        if ($this->businessContext->getCurrentBusiness($user)) {
            return null;
        }

        $memberships = $this->businessUserRepository->findActiveMembershipsForUser($user);
        if (count($memberships) > 1) {
            return new RedirectResponse($this->router->generate('app_business_switch'));
        }

        if (count($memberships) === 1) {
            $business = $memberships[0]->getBusiness();
            if ($business) {
                $this->businessContext->setCurrentBusiness($business);
                return new RedirectResponse($this->router->generate('app_dashboard'));
            }
        }

        return null;
    }
}
