<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class SeedOnboardingSectionsCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem'];

    private const array CARDS = ['accounts_payable', 'accounts_receivable', 'sales', 'marketing', 'crm', 'inventory'];

    public function testSeedsGlobalJsonIdempotentlyAndReportsMissingTemplates(): void
    {
        $this->artisan('kanvas:onboarding:seed-sections', ['app' => 0])
            ->expectsOutputToContain('Ginny')
            ->assertExitCode(1);

        $rows = OnboardingSection::query()->where('apps_id', 0)->whereIn('slug', self::CARDS)->get();
        $this->assertCount(6, $rows);
        $this->assertFalse($rows->firstWhere('slug', 'crm')->is_available);
        $this->assertFalse($rows->firstWhere('slug', 'marketing')->is_available);
        $this->assertSame('Pay my suppliers\' bills on time', $rows->firstWhere('slug', 'accounts_payable')->title);

        $this->artisan('kanvas:onboarding:seed-sections', ['app' => 0])->assertExitCode(1);

        $this->assertSame(6, OnboardingSection::query()->where('apps_id', 0)->whereIn('slug', self::CARDS)->count());
    }

    public function testSalesCardListsTheCompanyDefaultAgentsByTheirCodeNames(): void
    {
        $this->artisan('kanvas:onboarding:seed-sections', ['app' => 0])->assertExitCode(1);

        $sales = OnboardingSection::query()->where('apps_id', 0)->where('slug', 'sales')->firstOrFail();
        $sources = array_column($sales->config()->agents, 'source_agent');

        $this->assertEqualsCanonicalizing(
            ['Sally', 'firstMessageEngagerAgent', 'FollowUpEngagerAgent', 'voiceOutreachAgent', 'Polly'],
            $sources,
        );
    }
}
