<?php

namespace App\Service;

use App\Entity\Subscription;

class SubscriptionAccessResolver
{
    public const MODE_FULL = 'FULL';
    public const MODE_READONLY = 'READONLY';
    public const MODE_BLOCKED = 'BLOCKED';

    public function resolve(?Subscription $subscription): array
    {
        if (!$subscription) {
            return [
                'mode' => self::MODE_BLOCKED,
                'reason' => 'no_subscription',
                'endsAt' => null,
            ];
        }

        $status = $subscription->getStatus();
        if ($status === Subscription::STATUS_TRIAL) {
            $trialEndsAt = $subscription->getTrialEndsAt();
            if (!$trialEndsAt) {
                return [
                    'mode' => self::MODE_BLOCKED,
                    'reason' => 'trial_missing',
                    'endsAt' => null,
                ];
            }

            if ($trialEndsAt > new \DateTimeImmutable()) {
                return [
                    'mode' => self::MODE_FULL,
                    'reason' => 'trial_active',
                    'endsAt' => $trialEndsAt,
                ];
            }

            return [
                'mode' => self::MODE_READONLY,
                'reason' => 'trial_expired',
                'endsAt' => $trialEndsAt,
            ];
        }

        if ($status === Subscription::STATUS_ACTIVE) {
            return [
                'mode' => self::MODE_FULL,
                'reason' => 'active',
                'endsAt' => $subscription->getEndAt(),
            ];
        }

        if ($status === Subscription::STATUS_PAST_DUE) {
            return [
                'mode' => self::MODE_READONLY,
                'reason' => 'past_due',
                'endsAt' => $subscription->getEndAt(),
            ];
        }

        if ($status === Subscription::STATUS_SUSPENDED) {
            return [
                'mode' => self::MODE_READONLY,
                'reason' => 'suspended',
                'endsAt' => $subscription->getEndAt(),
            ];
        }

        if ($status === Subscription::STATUS_CANCELED) {
            return [
                'mode' => self::MODE_READONLY,
                'reason' => 'canceled',
                'endsAt' => $subscription->getEndAt(),
            ];
        }

        return [
            'mode' => self::MODE_BLOCKED,
            'reason' => 'unknown',
            'endsAt' => $subscription->getEndAt(),
        ];
    }
}
