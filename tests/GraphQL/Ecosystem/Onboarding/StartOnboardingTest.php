<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Onboarding;

use Baka\Search\SearchEngineResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class StartOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem'];

    private const string MUTATION = /** @lang GraphQL */ '
        mutation startOnboarding($input: StartOnboardingInput!) {
            startOnboarding(input: $input) {
                app { id name }
                app_key
                company { id name }
                onboarding { status }
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        // A new app indexes its first branch with the platform Typesense key (CI sets TYPESENSE_API_KEY);
        // a local .env without one would fail inside CreateAppsAction, so borrow the platform app's.
        if (trim((string) config('scout.typesense.api_key')) === '') {
            $settings = SearchEngineResolver::typesenseSettings(app(Apps::class));
            config(['scout.typesense.api_key' => $settings['typesense_api_key'] ?? 'test-key']);
            if (! empty($settings['typesense_nodes'])) {
                config(['scout.typesense.nodes' => $settings['typesense_nodes']]);
            }
        }
    }

    public function testAnUnverifiedEmailCanStartOnboarding(): void
    {
        $profile = auth()->user()->getAppProfile(app(Apps::class));
        $profile->is_verified = 0;
        $profile->saveOrFail();

        $workspace = $this->graphQL(self::MUTATION, ['input' => ['app_name' => 'Unverified ' . uniqid()]])->json('data.startOnboarding');

        $this->assertNotNull($workspace);
    }

    public function testCreatesAppOwnerCompanyAndOnboardingRow(): void
    {
        $name = 'Acme Test ' . uniqid();

        $response = $this->graphQL(self::MUTATION, ['input' => ['app_name' => $name]]);
        $response->assertSuccessful();

        $workspace = $response->json('data.startOnboarding');
        $this->assertNotNull($workspace, json_encode($response->json()));
        $this->assertSame($name, $workspace['app']['name']);
        $this->assertNotEmpty($workspace['app_key']);
        $this->assertSame($name, $workspace['company']['name']);
        $this->assertSame('STARTED', $workspace['onboarding']['status']);

        $newApp = Apps::getById((int) $workspace['app']['id']);
        $this->assertTrue($newApp->getBool(KnowledgeConfigurationEnum::ENABLED->value), 'a self-serve app indexes its company knowledge from day one');
        $this->assertArrayHasKey(
            KnowledgeConfigurationEnum::ENABLED->value,
            $newApp->getAllSettings(onlyPublicSettings: true, fromRedis: false),
            'the flag is visible on the settings screen, which lists public settings only',
        );
        /** @var Users $user */
        $user = auth()->user();
        $this->assertTrue($user->isOwnerOf($newApp));

        $row = CompanyOnboarding::query()->where('apps_id', $newApp->getId())->firstOrFail();
        $this->assertSame((int) $workspace['company']['id'], (int) $row->companies_id);
        $this->assertSame(app(Apps::class)->getId(), (int) $row->dataGet('platform_apps_id'));
    }

    public function testSecondCallReturnsTheSameWorkspace(): void
    {
        $name = 'Acme Twice ' . uniqid();

        $first = $this->graphQL(self::MUTATION, ['input' => ['app_name' => $name]])->json('data.startOnboarding');
        $second = $this->graphQL(self::MUTATION, ['input' => ['app_name' => $name]])->json('data.startOnboarding');

        $this->assertSame($first['app']['id'], $second['app']['id']);
        $this->assertSame(1, Apps::query()->where('name', $name)->count());
    }

    public function testRefusedWhileAnotherStartIsInFlightForTheSameUser(): void
    {
        $lock = Cache::lock('onboarding:start:' . auth()->user()->getId(), 30);
        $this->assertTrue($lock->get());

        try {
            $response = $this->graphQL(self::MUTATION, ['input' => ['app_name' => 'Acme Locked ' . uniqid()]]);
        } finally {
            $lock->release();
        }

        $this->assertNull($response->json('data.startOnboarding'));
        $this->assertStringContainsStringIgnoringCase('already', (string) $response->json('errors.0.message'));
        $this->assertSame(0, Apps::query()->where('name', 'like', 'Acme Locked %')->count());
    }
}
