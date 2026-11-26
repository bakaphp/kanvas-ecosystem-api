<?php

declare(strict_types=1);

namespace Tests\Unit\ActionEngine;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Services\EngagementUploadTokenService;
use Tests\TestCase;

final class EngagementUploadTokenServiceTest extends TestCase
{
    public function testIssuesAndVerifiesAnEngagementBoundToken(): void
    {
        config(['app.key' => 'test-upload-signing-key']);
        $engagement = new Engagement([
            'uuid' => (string) Str::uuid(),
            'companies_id' => 42,
        ]);
        $expiresAt = Carbon::now()->addHours(72);
        $service = new EngagementUploadTokenService();

        $token = $service->issue($engagement, 100, $expiresAt);

        $this->assertSame([
            'engagement_uuid' => $engagement->uuid,
            'company_id' => 42,
            'exp' => $expiresAt->getTimestamp(),
            'max_files' => 100,
        ], $service->verify($token));
    }

    public function testRejectsExpiredAndTamperedTokens(): void
    {
        config(['app.key' => 'test-upload-signing-key']);
        $engagement = new Engagement([
            'uuid' => (string) Str::uuid(),
            'companies_id' => 42,
        ]);
        $service = new EngagementUploadTokenService();
        $expiredToken = $service->issue($engagement, 1, Carbon::now()->subSecond());
        $validToken = $service->issue($engagement, 1);

        $this->assertNull($service->verify($expiredToken));
        $this->assertNull($service->verify($validToken . 'tampered'));
    }

    public function testDefaultExpirationIsSeventyTwoHours(): void
    {
        config(['app.key' => 'test-upload-signing-key']);
        Carbon::setTestNow('2026-11-26 12:00:00');
        $engagement = new Engagement([
            'uuid' => (string) Str::uuid(),
            'companies_id' => 42,
        ]);

        try {
            $claims = (new EngagementUploadTokenService())->verify(
                (new EngagementUploadTokenService())->issue($engagement, 3)
            );
        } finally {
            Carbon::setTestNow();
        }

        $this->assertNotNull($claims);
        $this->assertSame(Carbon::parse('2026-11-29 12:00:00')->getTimestamp(), $claims['exp']);
    }
}
