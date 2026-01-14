<?php

namespace App\Security;

use App\Entity\Business;
use App\Entity\BusinessUser;
use App\Entity\User;
use App\Repository\BusinessRepository;
use App\Repository\BusinessUserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class BusinessContext
{
    private const SESSION_KEY = 'current_business_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly BusinessRepository $businessRepository,
        private readonly BusinessUserRepository $businessUserRepository,
        private readonly Security $security,
    ) {
    }

    public function getCurrentBusiness(?User $user = null): ?Business
    {
        $user ??= $this->resolveUser();
        if (!$user instanceof User) {
            return null;
        }

        $session = $this->requestStack->getSession();
        $businessId = $session?->get(self::SESSION_KEY);
        if (is_numeric($businessId) && (int) $businessId > 0) {
            $business = $this->businessRepository->find((int) $businessId);
            if ($business instanceof Business) {
                $membership = $this->businessUserRepository->findActiveMembership($user, $business);
                if ($membership instanceof BusinessUser) {
                    return $business;
                }
            }
        }

        $memberships = $this->businessUserRepository->findActiveMembershipsForUser($user);
        if (count($memberships) === 1) {
            $business = $memberships[0]->getBusiness();
            if ($business instanceof Business) {
                $this->setCurrentBusiness($business);

                return $business;
            }
        }

        return null;
    }

    public function requireCurrentBusiness(?User $user = null): Business
    {
        $user ??= $this->resolveUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Necesitás iniciar sesión.');
        }

        $business = $this->getCurrentBusiness($user);
        if (!$business instanceof Business) {
            throw new AccessDeniedException('No hay un comercio seleccionado.');
        }

        $membership = $this->businessUserRepository->findActiveMembership($user, $business);
        if (!$membership instanceof BusinessUser) {
            throw new AccessDeniedException('No tenés acceso a este comercio.');
        }

        return $business;
    }

    public function setCurrentBusiness(Business $business): void
    {
        $businessId = $business->getId();
        if ($businessId === null) {
            return;
        }

        $session = $this->requestStack->getSession();
        if ($session) {
            $session->set(self::SESSION_KEY, $businessId);
        }
    }

    public function getUserMembershipForCurrentBusiness(User $user): ?BusinessUser
    {
        $business = $this->getCurrentBusiness($user);
        if (!$business instanceof Business) {
            return null;
        }

        return $this->businessUserRepository->findActiveMembership($user, $business);
    }

    public function hasRoleForCurrentBusiness(User $user, string $role): bool
    {
        $membership = $this->getUserMembershipForCurrentBusiness($user);
        if (!$membership instanceof BusinessUser) {
            return false;
        }

        return $membership->getRole() === $role;
    }

    private function resolveUser(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }
}
