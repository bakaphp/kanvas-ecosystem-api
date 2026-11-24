<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Enums\ResearchStatusEnum;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Intelligence\Knowledge\Research\Jobs\ResearchCompanyWebsiteJob;
use Tests\TestCase;
use Tests\Traits\ReadsOnboardingGraphQL;

final class ResearchCompanyWebsiteMutationTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsOnboardingGraphQL;

    protected $connectionsToTransact = [null, 'ecosystem'];

    private const string WEBSITE = 'https://93.184.216.34';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $company = $this->currentCompany();
        $company->website = '';
        $company->saveOrFail();
    }

    public function testProfileUpdateWithNewWebsiteDispatchesResearch(): void
    {
        $data = $this->updateProfile(['website' => self::WEBSITE, 'offering' => 'Chairs']);

        $this->assertSame('QUEUED', $data['research']['status']);
        $this->assertSame(self::WEBSITE, $data['research']['website']);
        Queue::assertPushed(ResearchCompanyWebsiteJob::class, 1);
        Queue::assertPushed(
            ResearchCompanyWebsiteJob::class,
            fn (ResearchCompanyWebsiteJob $job): bool => $job->website === self::WEBSITE
                && $job->company->getId() === $this->currentCompany()->getId()
                && $job->queue === 'onboarding',
        );
    }

    public function testSameWebsiteDoesNotRedispatch(): void
    {
        $this->updateProfile(['website' => self::WEBSITE]);
        $this->updateProfile(['website' => self::WEBSITE, 'job_role' => 'Founder']);
        $this->updateProfile(['offering' => 'Chairs']);

        Queue::assertPushed(ResearchCompanyWebsiteJob::class, 1);
    }

    public function testRerunWithin24hRefusedWithoutForce(): void
    {
        $this->updateProfile(['website' => self::WEBSITE]);
        $this->row()->dataSet('research', [
            'status' => ResearchStatusEnum::INDEXED->value,
            'website' => self::WEBSITE,
            'finished_at' => Carbon::now()->subHours(2)->toIso8601String(),
        ]);

        $refused = $this->research(self::WEBSITE, force: false);
        $this->assertNull($refused->json('data.researchCompanyWebsite'));
        $this->assertStringContainsString('24', (string) $refused->json('errors.0.message'));
        Queue::assertPushed(ResearchCompanyWebsiteJob::class, 1);

        $forced = $this->research(self::WEBSITE, force: true);
        $this->assertSame('QUEUED', $forced->json('data.researchCompanyWebsite.status'));
        Queue::assertPushed(ResearchCompanyWebsiteJob::class, 2);
    }

    public function testResearchOnAnOldRunQueuesWithoutForce(): void
    {
        $this->row()->dataSet('research', [
            'status' => ResearchStatusEnum::INDEXED->value,
            'website' => self::WEBSITE,
            'finished_at' => Carbon::now()->subDays(3)->toIso8601String(),
        ]);

        $response = $this->research('93.184.216.34', force: false);

        $this->assertSame('QUEUED', $response->json('data.researchCompanyWebsite.status'));
        $this->assertSame(self::WEBSITE, $this->currentCompany()->refresh()->website);
        Queue::assertPushed(ResearchCompanyWebsiteJob::class, 1);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function updateProfile(array $input): array
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation($input: OnboardingProfileInput!) {
                updateOnboardingProfile(input: $input) { status research { status website } }
            }
        ', ['input' => $input]);
        $data = $response->json('data.updateOnboardingProfile');
        $this->assertNotNull($data, json_encode($response->json()));

        return $data;
    }

    private function research(string $website, bool $force): TestResponse
    {
        return $this->graphQL(/** @lang GraphQL */ '
            mutation($website: String!, $force: Boolean) {
                researchCompanyWebsite(website: $website, force: $force) { status website error }
            }
        ', ['website' => $website, 'force' => $force]);
    }

    private function row(): CompanyOnboarding
    {
        return new OnboardingStateService()->forCompany(app(Apps::class), $this->currentCompany(), auth()->user());
    }

    public function testForceIsRateLimitedWhileTheLastForcedRunIsFresh(): void
    {
        $first = $this->research(self::WEBSITE, force: true);
        $this->assertSame('QUEUED', $first->json('data.researchCompanyWebsite.status'), json_encode($first->json()));

        $second = $this->research(self::WEBSITE, force: true);

        $this->assertNull($second->json('data.researchCompanyWebsite'));
        $this->assertStringContainsStringIgnoringCase('minutes', (string) $second->json('errors.0.message'));
    }
}
