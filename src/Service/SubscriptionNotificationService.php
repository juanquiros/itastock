<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\EmailPreference;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EmailPreferenceRepository;

class SubscriptionNotificationService
{
    public function __construct(
        private readonly EmailPreferenceRepository $emailPreferenceRepository,
        private readonly EmailSender $emailSender,
    ) {
    }

    public function sendTrialExpiringIfNeeded(Subscription $subscription, \DateTimeImmutable $now): void
    {
        $trialEndsAt = $subscription->getTrialEndsAt();
        if ($trialEndsAt === null) {
            return;
        }

        $windowStart = $now->modify('+3 days');
        $windowEnd = $now->modify('+4 days');

        if ($trialEndsAt < $windowStart || $trialEndsAt >= $windowEnd) {
            return;
        }

        $this->notifyAdmins(
            $subscription,
            'DEMO_EXPIRING_3_DAYS',
            'Tu demo termina en 3 días',
            'emails/demo/demo_expiring_3_days.html.twig',
            [
                'trialEndsAt' => $trialEndsAt,
            ],
            $trialEndsAt->setTime(0, 0, 0),
            $trialEndsAt->setTime(23, 59, 59),
        );
    }

    public function sendTrialExpiredIfNeeded(Subscription $subscription, \DateTimeImmutable $now): void
    {
        $trialEndsAt = $subscription->getTrialEndsAt();
        if ($trialEndsAt === null || $trialEndsAt >= $now) {
            return;
        }

        $this->notifyAdmins(
            $subscription,
            'DEMO_EXPIRED',
            'Tu demo finalizó',
            'emails/demo/demo_expired.html.twig',
            [
                'trialEndsAt' => $trialEndsAt,
            ],
            $trialEndsAt->setTime(0, 0, 0),
            $trialEndsAt->setTime(23, 59, 59),
        );
    }

    public function sendNextChargeReminderIfNeeded(Subscription $subscription, \DateTimeImmutable $now): void
    {
        $nextChargeAt = $subscription->getNextPaymentAt() ?? $subscription->getEndAt();
        if ($nextChargeAt === null) {
            return;
        }

        $windowStart = $now->modify('+7 days');
        $windowEnd = $now->modify('+8 days');

        if ($nextChargeAt < $windowStart || $nextChargeAt >= $windowEnd) {
            return;
        }

        $this->notifyAdmins(
            $subscription,
            'SUBSCRIPTION_NEXT_CHARGE_7_DAYS',
            'Recordatorio de próximo cobro',
            'emails/subscription/subscription_next_charge_7_days.html.twig',
            [
                'nextChargeAt' => $nextChargeAt,
                'planName' => $subscription->getPlan()?->getName(),
            ],
            $nextChargeAt->setTime(0, 0, 0),
            $nextChargeAt->setTime(23, 59, 59),
        );
    }

    public function sendCancellationExpiringIfNeeded(Subscription $subscription, \DateTimeImmutable $now): void
    {
        $endAt = $subscription->getEndAt();
        if ($endAt === null) {
            return;
        }

        $windowStart = $now->modify('+7 days');
        $windowEnd = $now->modify('+8 days');

        if ($endAt < $windowStart || $endAt >= $windowEnd) {
            return;
        }

        $this->notifyAdmins(
            $subscription,
            'SUBSCRIPTION_CANCELED_OR_EXPIRING_7_DAYS',
            'Tu suscripción está por finalizar',
            'emails/subscription/subscription_canceled_or_expiring_7_days.html.twig',
            [
                'planName' => $subscription->getPlan()?->getName(),
                'endsAt' => $endAt,
            ],
            $endAt->setTime(0, 0, 0),
            $endAt->setTime(23, 59, 59),
        );
    }

    public function sendNoSubscriptionIfNeeded(Subscription $subscription, \DateTimeImmutable $now): void
    {
        if ($subscription->getStatus() !== Subscription::STATUS_CANCELED) {
            return;
        }

        $endAt = $subscription->getEndAt();
        if ($endAt !== null && $endAt >= $now) {
            return;
        }

        $this->notifyAdmins(
            $subscription,
            'SUBSCRIPTION_NO_SUBSCRIPTION',
            'Tu comercio no tiene una suscripción activa',
            'emails/subscription/subscription_no_subscription.html.twig',
            [
                'businessName' => $subscription->getBusiness()?->getName(),
            ],
            null,
            null,
        );
    }

    public function onDemoEnabled(Subscription $subscription): void
    {
        $this->notifyAdmins(
            $subscription,
            'DEMO_ENABLED',
            'Demo habilitada',
            'emails/demo/demo_enabled.html.twig',
            [
                'trialEndsAt' => $subscription->getTrialEndsAt(),
                'businessName' => $subscription->getBusiness()?->getName(),
            ],
            null,
            null,
        );
    }

    public function onSubscriptionActivated(Subscription $subscription): void
    {
        $this->notifyAdmins(
            $subscription,
            'SUBSCRIPTION_ACTIVATED',
            'Suscripción activada',
            'emails/subscription/subscription_activated.html.twig',
            [
                'planName' => $subscription->getPlan()?->getName(),
                'nextChargeAt' => $subscription->getNextPaymentAt(),
            ],
            null,
            null,
        );
    }

    public function onPaymentReceived(Subscription $subscription, ?\DateTimeImmutable $nextChargeAt = null): void
    {
        $this->notifyAdmins(
            $subscription,
            'SUBSCRIPTION_PAYMENT_RECEIVED',
            'Pago recibido',
            'emails/subscription/subscription_payment_received.html.twig',
            [
                'planName' => $subscription->getPlan()?->getName(),
                'nextChargeAt' => $nextChargeAt,
                'paidAt' => new \DateTimeImmutable(),
            ],
            null,
            null,
        );
    }

    public function onPaymentFailed(Subscription $subscription): void
    {
        $this->notifyAdmins(
            $subscription,
            'SUBSCRIPTION_PAYMENT_FAILED',
            'Pago rechazado',
            'emails/subscription/subscription_payment_failed.html.twig',
            [
                'planName' => $subscription->getPlan()?->getName(),
                'attemptedAt' => new \DateTimeImmutable(),
            ],
            null,
            null,
        );
    }

    public function onSubscriptionChangeApplied(Subscription $subscription, ?string $planName = null): void
    {
        $this->notifyAdmins(
            $subscription,
            'SUBSCRIPTION_CHANGE_APPLIED',
            'Cambio aplicado',
            'emails/subscription/subscription_change_applied.html.twig',
            [
                'planName' => $planName ?? $subscription->getPlan()?->getName(),
                'appliedAt' => new \DateTimeImmutable(),
            ],
            null,
            null,
        );
    }

    public function onCanceled(Subscription $subscription): void
    {
        $this->notifyAdmins(
            $subscription,
            'SUBSCRIPTION_CANCELED_OR_EXPIRING_7_DAYS',
            'Suscripción cancelada',
            'emails/subscription/subscription_canceled_or_expiring_7_days.html.twig',
            [
                'planName' => $subscription->getPlan()?->getName(),
                'endsAt' => $subscription->getEndAt(),
            ],
            null,
            null,
        );
    }

    public function onStoreModeChanged(Subscription $subscription, string $mode, \DateTimeImmutable $changedAt): void
    {
        $this->notifyAdmins(
            $subscription,
            'STORE_MODE_CHANGED',
            'Modo de tienda actualizado',
            'emails/reports/store_mode_changed.html.twig',
            [
                'storeMode' => $mode,
                'changedAt' => $changedAt,
            ],
            null,
            null,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function notifyAdmins(
        Subscription $subscription,
        string $type,
        string $subject,
        string $template,
        array $context,
        ?\DateTimeImmutable $periodStart,
        ?\DateTimeImmutable $periodEnd,
    ): void {
        $business = $subscription->getBusiness();
        if (!$business instanceof Business) {
            return;
        }

        foreach ($business->getUsers() as $user) {
            if (!$user instanceof User || !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                continue;
            }

            $preference = $this->emailPreferenceRepository->getEffectivePreference($business, $user);
            if (!$this->canSendPreference($preference)) {
                continue;
            }

            $recipientEmail = $user->getEmail() ?? $user->getUserIdentifier();
            $payload = array_merge($context, [
                'userName' => $user->getFullName() ?? $user->getUserIdentifier(),
                'businessName' => $business->getName(),
            ]);

            $this->emailSender->sendTemplatedEmail(
                $type,
                $recipientEmail,
                'ADMIN',
                $subject,
                $template,
                $payload,
                $business,
                $subscription,
                $periodStart,
                $periodEnd,
            );
        }
    }

    private function canSendPreference(EmailPreference $preference): bool
    {
        return $preference->isEnabled() && $preference->isSubscriptionAlertsEnabled();
    }
}
