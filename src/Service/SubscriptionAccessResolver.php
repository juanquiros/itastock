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

        $overrideUntil = $subscription->getOverrideUntil();
        $overrideMode = $subscription->getOverrideMode();
        if ($overrideMode && $overrideUntil instanceof \DateTimeImmutable && $overrideUntil > new \DateTimeImmutable()) {
            return [
                'mode' => $this->normalizeOverrideMode($overrideMode),
                'reason' => 'override',
                'endsAt' => $overrideUntil,
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
            $endAt = $subscription->getEndAt();
            $nextChargeAt = $subscription->getNextPaymentAt();
            if (
                $endAt instanceof \DateTimeImmutable
                && $endAt <= new \DateTimeImmutable()
                && !($nextChargeAt instanceof \DateTimeImmutable && $nextChargeAt > new \DateTimeImmutable())
            ) {
                return [
                    'mode' => self::MODE_READONLY,
                    'reason' => 'active_expired',
                    'endsAt' => $endAt,
                ];
            }

            return [
                'mode' => self::MODE_FULL,
                'reason' => 'active',
                'endsAt' => $nextChargeAt ?? $endAt,
            ];
        }

        if ($status === Subscription::STATUS_PAST_DUE) {
            return [
                'mode' => self::MODE_READONLY,
                'reason' => 'past_due',
                'endsAt' => $subscription->getEndAt(),
            ];
        }

        if ($status === Subscription::STATUS_PENDING) {
            return [
                'mode' => self::MODE_BLOCKED,
                'reason' => 'pending',
                'endsAt' => null,
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
            $nextPaymentAt = $subscription->getNextPaymentAt();
            if ($nextPaymentAt instanceof \DateTimeImmutable) {
                $graceDays = max(0, $subscription->getGracePeriodDays());
                if ($nextPaymentAt > new \DateTimeImmutable()) {
                    return [
                        'mode' => self::MODE_FULL,
                        'reason' => 'canceled_pending',
                        'endsAt' => $nextPaymentAt,
                    ];
                }

                $graceEndsAt = $nextPaymentAt->modify(sprintf('+%d days', $graceDays));
                if ($graceEndsAt > new \DateTimeImmutable()) {
                    return [
                        'mode' => self::MODE_READONLY,
                        'reason' => 'grace_period',
                        'endsAt' => $graceEndsAt,
                    ];
                }
            }

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

    private function normalizeOverrideMode(string $overrideMode): string
    {
        return match ($overrideMode) {
            Subscription::OVERRIDE_FULL => self::MODE_FULL,
            Subscription::OVERRIDE_READONLY => self::MODE_READONLY,
            Subscription::OVERRIDE_BLOCKED => self::MODE_BLOCKED,
            default => self::MODE_BLOCKED,
        };
    }
}
