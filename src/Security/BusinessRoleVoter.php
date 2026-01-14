<?php

namespace App\Security;

use App\Entity\BusinessUser;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class BusinessRoleVoter extends Voter
{
    public const OWNER = 'BUSINESS_OWNER';
    public const ADMIN = 'BUSINESS_ADMIN';
    public const SELLER = 'BUSINESS_SELLER';
    public const READONLY = 'BUSINESS_READONLY';

    public function __construct(
        private readonly BusinessContext $businessContext,
        private readonly Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::OWNER, self::ADMIN, self::SELLER, self::READONLY], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($this->security->isGranted('ROLE_PLATFORM_ADMIN')) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $membership = $this->businessContext->getUserMembershipForCurrentBusiness($user);
        if (!$membership instanceof BusinessUser) {
            return false;
        }

        $role = $membership->getRole();

        return match ($attribute) {
            self::OWNER => $role === BusinessUser::ROLE_OWNER,
            self::ADMIN => in_array($role, [BusinessUser::ROLE_OWNER, BusinessUser::ROLE_ADMIN], true),
            self::SELLER => in_array($role, [BusinessUser::ROLE_OWNER, BusinessUser::ROLE_ADMIN, BusinessUser::ROLE_SELLER], true),
            self::READONLY => in_array($role, [BusinessUser::ROLE_OWNER, BusinessUser::ROLE_ADMIN, BusinessUser::ROLE_SELLER, BusinessUser::ROLE_READONLY], true),
            default => false,
        };
    }
}
