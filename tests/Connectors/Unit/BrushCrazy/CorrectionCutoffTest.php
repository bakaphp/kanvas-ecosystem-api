<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Support\CorrectionCutoff;
use Kanvas\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the cutoff is an app setting, which lands in Redis and on the `ecosystem` connection and
 * would leak into every parallel test reading it.
 */
#[Group('serial')]
final class CorrectionCutoffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(Apps::class)->del(ConfigurationEnum::BRUSHCRAZY_TZ_CORRECTION_CUTOFF->value);
    }

    protected function tearDown(): void
    {
        app(Apps::class)->del(ConfigurationEnum::BRUSHCRAZY_TZ_CORRECTION_CUTOFF->value);

        parent::tearDown();
    }

    public function testStoredIsNullBeforeTheFirstImport(): void
    {
        $this->assertNull(CorrectionCutoff::stored(app(Apps::class)));
    }

    public function testParseReadsAnyOffsetAsUtcAndBlankAsNull(): void
    {
        $this->assertNull(CorrectionCutoff::parse(null));
        $this->assertNull(CorrectionCutoff::parse(''));
        $this->assertSame(
            '2026-10-08T18:00:00+00:00',
            CorrectionCutoff::parse('2026-10-08T12:00:00-06:00')->toIso8601String()
        );
    }

    /**
     * The import command reads BRUSHCRAZY_CUTOVER_AT through the same parser, so the command and
     * the mirror agree on the instant.
     */
    public function testInstantSettingReadsTheCutoverThroughTheSameParser(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT->value, '2026-12-01T00:00:00-05:00');

        try {
            $this->assertSame(
                '2026-12-01T05:00:00+00:00',
                CorrectionCutoff::instantSetting($app, ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT)->toIso8601String()
            );
        } finally {
            $app->del(ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT->value);
        }
    }

    public function testPastCutoverOnlyFromTheCutoverInstantOn(): void
    {
        $app = app(Apps::class);
        $this->assertFalse(CorrectionCutoff::isPastCutover($app));

        $app->set(ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT->value, '2026-12-01T00:00:00+00:00');

        try {
            $this->assertFalse(CorrectionCutoff::isPastCutover($app, Carbon::parse('2026-11-30T23:59:59+00:00')));
            $this->assertTrue(CorrectionCutoff::isPastCutover($app, Carbon::parse('2026-12-01T00:00:00+00:00')));
        } finally {
            $app->del(ConfigurationEnum::BRUSHCRAZY_CUTOVER_AT->value);
        }
    }

    public function testPinnedFailsBeforeTheFirstImport(): void
    {
        $this->expectException(ValidationException::class);

        CorrectionCutoff::pinned(app(Apps::class));
    }

    public function testFirstPinDefaultsToNowAndLaterRunsReuseIt(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        $first = CorrectionCutoff::pin(app(Apps::class));

        Carbon::setTestNow('2026-11-01 08:00:00');
        $second = CorrectionCutoff::pin(app(Apps::class));

        $this->assertSame('2026-10-08T12:00:00+00:00', $first->toIso8601String());
        $this->assertTrue($second->equalTo($first));
        $this->assertTrue(CorrectionCutoff::pinned(app(Apps::class))->equalTo($first));

        Carbon::setTestNow();
    }

    public function testRequestedCutoffIsHonouredOnlyOnTheFirstPin(): void
    {
        $requested = Carbon::parse('2026-09-01 00:00:00', 'UTC');

        $this->assertTrue(CorrectionCutoff::pin(app(Apps::class), $requested)->equalTo($requested));
        $this->assertTrue(CorrectionCutoff::pin(app(Apps::class), $requested->copy())->equalTo($requested));

        $this->expectException(ValidationException::class);

        CorrectionCutoff::pin(app(Apps::class), Carbon::parse('2026-10-01 00:00:00', 'UTC'));
    }
}
