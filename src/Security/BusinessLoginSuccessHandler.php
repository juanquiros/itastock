<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\BusinessUserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class BusinessLoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    use TargetPathTrait;

    public function __construct(
        private readonly RouterInterface $router,
        private readonly BusinessContext $businessContext,
        private readonly BusinessUserRepository $businessUserRepository,
        private readonly Security $security,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), 'main')) {
            return new RedirectResponse($targetPath);
        }

        if ($this->security->isGranted('ROLE_PLATFORM_ADMIN')) {
            return new RedirectResponse($this->router->generate('platform_business_index'));
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return new RedirectResponse($this->router->generate('app_login'));
        }

        $memberships = $this->businessUserRepository->findActiveMembershipsForUser($user);

        if (count($memberships) === 1) {
            $business = $memberships[0]->getBusiness();
            if ($business) {
                $this->businessContext->setCurrentBusiness($business);
                return new RedirectResponse($this->router->generate('app_dashboard'));
            }
        }

        if (count($memberships) > 1) {
            return new RedirectResponse($this->router->generate('app_business_switch'));
        }

        return new RedirectResponse($this->router->generate('public_home'));
    }
}
