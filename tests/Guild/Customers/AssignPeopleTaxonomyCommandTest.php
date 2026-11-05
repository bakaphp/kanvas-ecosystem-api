<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Classification\DataTransferObject\PeopleTaxonomy;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationConfigurationEnum as ConfigurationEnum;
use Kanvas\Guild\Customers\Classification\Services\PeopleClassificationSettings;
use Kanvas\Guild\Customers\Classification\Support\DefaultPeopleTaxonomy;
use Kanvas\Users\Models\UserCompanyApps;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial because company settings live in shared Redis.
 */
#[Group('serial')]
final class AssignPeopleTaxonomyCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private Apps $kanvasApp;
    private Companies $company;
    private array $previousSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        foreach ([ConfigurationEnum::TAXONOMY, ConfigurationEnum::ENABLED] as $key) {
            $this->previousSettings[$key->value] = $this->company->get($key->value);
        }

        $this->company->del(ConfigurationEnum::TAXONOMY->value);
        $this->company->set(ConfigurationEnum::ENABLED->value, false);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousSettings as $key => $value) {
            $value === null ? $this->company->del($key) : $this->company->set($key, $value);
        }

        parent::tearDown();
    }

    public function test_it_assigns_the_default_to_a_company_without_turning_classification_on(): void
    {
        $this->assign()->assertSuccessful();

        $settings = PeopleClassificationSettings::for($this->kanvasApp, $this->company);

        $this->assertSame(array_keys(DefaultPeopleTaxonomy::VALUE['levels']), array_keys($settings->taxonomy->levels));
        $this->assertFalse($settings->isActive());
    }

    public function test_it_refuses_to_replace_a_taxonomy_unless_forced(): void
    {
        $custom = ['levels' => ['A' => ['label' => 'A']], 'areas' => ['B' => ['label' => 'B']]];
        $this->company->set(ConfigurationEnum::TAXONOMY->value, $custom);

        $this->assign()->assertFailed();
        $this->assertSame(['A'], array_keys($this->company->get(ConfigurationEnum::TAXONOMY->value)['levels']));

        $this->assign(['--force' => true])->assertSuccessful();
        $this->assertArrayHasKey('MANAGER', $this->company->get(ConfigurationEnum::TAXONOMY->value)['levels']);
    }

    public function test_without_a_company_it_assigns_to_the_app(): void
    {
        $key = ConfigurationEnum::TAXONOMY->value;
        $previous = $this->kanvasApp->get($key);
        $this->kanvasApp->del($key);

        try {
            $this->artisan('kanvas:people-classification-assign-taxonomy', [
                'app_id' => $this->kanvasApp->getId(),
            ])->assertSuccessful();

            $this->assertArrayHasKey('MANAGER', $this->kanvasApp->get($key)['levels']);
            $this->assertNull($this->company->get($key));
        } finally {
            $previous === null ? $this->kanvasApp->del($key) : $this->kanvasApp->set($key, $previous);
        }
    }

    public function test_a_company_outside_the_app_is_refused(): void
    {
        UserCompanyApps::where('companies_id', $this->company->getId())
            ->where('apps_id', $this->kanvasApp->getId())
            ->delete();

        $this->assign()->assertFailed();

        $this->assertNull($this->company->get(ConfigurationEnum::TAXONOMY->value));
    }

    public function test_the_default_is_valid_and_its_tags_never_contain_a_comma(): void
    {
        $taxonomy = PeopleTaxonomy::fromSetting(DefaultPeopleTaxonomy::VALUE);

        $this->assertCount(5, $taxonomy->levels);
        $this->assertCount(8, $taxonomy->areas);

        foreach ($taxonomy->allTags() as $tag) {
            $this->assertStringNotContainsString(',', $tag);
        }
    }

    public function test_a_deleted_company_app_association_is_refused(): void
    {
        UserCompanyApps::where('companies_id', $this->company->getId())
            ->where('apps_id', $this->kanvasApp->getId())
            ->update(['is_deleted' => 1]);

        $this->assign()->assertFailed();

        $this->assertNull($this->company->get(ConfigurationEnum::TAXONOMY->value));
    }

    private function assign(array $options = []): mixed
    {
        return $this->artisan('kanvas:people-classification-assign-taxonomy', [
            'app_id' => $this->kanvasApp->getId(),
            '--company_id' => $this->company->getId(),
            ...$options,
        ]);
    }
}
