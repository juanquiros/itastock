<?php

namespace App\Tests\Security;

use App\Security\EmailContentPolicy;
use PHPUnit\Framework\TestCase;

class EmailContentPolicyTest extends TestCase
{
    public function testReportWeeklyNotAllowedForSeller(): void
    {
        $policy = new EmailContentPolicy();

        $this->expectException(\DomainException::class);
        $policy->assertAllowedRecipientRole('SELLER', 'REPORT_WEEKLY');
    }

    public function testPlatformDigestOmitsTotalsByBusiness(): void
    {
        $policy = new EmailContentPolicy();

        $context = [
            'totalsByBusiness' => [
                ['business' => 'A', 'total' => 1200],
            ],
            'aggregatedTotals' => [
                'totalSales' => 1200,
            ],
        ];

        $sanitized = $policy->sanitizeContext('PLATFORM', 'PLATFORM_WEEKLY', $context);

        self::assertArrayNotHasKey('totalsByBusiness', $sanitized);
        self::assertArrayHasKey('aggregatedTotals', $sanitized);
    }

    public function testSensitiveKeysAreRemovedFromContext(): void
    {
        $policy = new EmailContentPolicy();

        $context = [
            'mpPreapprovalId' => 'preapproval',
            'card_token' => 'token',
            'access_token' => 'secret',
            'nested' => [
                'access_token' => 'secret2',
                'safe' => 'ok',
            ],
        ];

        $sanitized = $policy->sanitizeContext('ADMIN', 'REPORT_WEEKLY', $context);

        self::assertArrayNotHasKey('mpPreapprovalId', $sanitized);
        self::assertArrayNotHasKey('card_token', $sanitized);
        self::assertArrayNotHasKey('access_token', $sanitized);
        self::assertArrayHasKey('nested', $sanitized);
        self::assertArrayNotHasKey('access_token', $sanitized['nested']);
        self::assertSame('ok', $sanitized['nested']['safe']);
    }
}
