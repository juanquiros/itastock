<?php

namespace App\Security;

class EmailContentPolicy
{
    private const SENSITIVE_KEYS = [
        'mppreapprovalid',
        'mp_preapproval_id',
        'card_token_id',
        'cardtokenid',
        'card_token',
        'access_token',
    ];

    public function assertAllowedRecipientRole(string $role, string $emailType): void
    {
        if ($role === 'SELLER' && $this->isReportType($emailType)) {
            throw new \DomainException('Role SELLER cannot receive report emails.');
        }
    }

    public function sanitizeContext(string $role, string $emailType, array $context): array
    {
        $context = $this->removeSensitiveKeys($context);

        if ($role === 'PLATFORM') {
            $context = $this->removePlatformSensitiveDetails($context);
        }

        if ($role === 'SELLER') {
            $context = $this->removeSellerSensitiveDetails($context);
        }

        if ($role === 'PUBLIC') {
            $context = $this->filterPublicContext($context);
        }

        return $context;
    }

    private function isReportType(string $emailType): bool
    {
        return str_starts_with($emailType, 'REPORT_');
    }

    private function removeSensitiveKeys(array $context): array
    {
        $filtered = [];

        foreach ($context as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $filtered[$key] = $this->removeSensitiveKeys($value);
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function isSensitiveKey(string|int $key): bool
    {
        if (!is_string($key)) {
            return false;
        }

        return in_array(mb_strtolower($key), self::SENSITIVE_KEYS, true);
    }

    private function removePlatformSensitiveDetails(array $context): array
    {
        $blockedKeys = [
            'businessTotals',
            'totalsByBusiness',
            'salesByBusiness',
            'cashByBusiness',
        ];

        foreach ($blockedKeys as $blockedKey) {
            if (array_key_exists($blockedKey, $context)) {
                unset($context[$blockedKey]);
            }
        }

        return $context;
    }

    private function removeSellerSensitiveDetails(array $context): array
    {
        $filtered = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && $this->containsSensitiveMetricKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $filtered[$key] = $this->removeSellerSensitiveDetails($value);
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function containsSensitiveMetricKey(string $key): bool
    {
        $lower = mb_strtolower($key);

        foreach (['amount', 'total', 'revenue', 'cash', 'sales'] as $token) {
            if (str_contains($lower, $token)) {
                return true;
            }
        }

        return false;
    }

    private function filterPublicContext(array $context): array
    {
        $allowedKeys = ['name', 'email', 'ctaUrl', 'planName', 'trialEndsAt'];
        $filtered = [];

        foreach ($allowedKeys as $allowedKey) {
            if (array_key_exists($allowedKey, $context)) {
                $filtered[$allowedKey] = $context[$allowedKey];
            }
        }

        return $filtered;
    }
}
