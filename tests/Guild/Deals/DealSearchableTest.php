<?php

declare(strict_types=1);

namespace Tests\Guild\Deals;

use Baka\Traits\DynamicSearchableTrait;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use ReflectionClass;
use Tests\TestCase;

final class DealSearchableTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function testDealUsesTheEngineBackedSearchableTrait(): void
    {
        $this->assertContains(
            DynamicSearchableTrait::class,
            new ReflectionClass(Deal::class)->getTraitNames(),
            'Deal must resolve a per-app engine; DatabaseSearchableTrait scans MySQL instead.',
        );
    }

    public function testSearchableIdIsAStringSoTypesenseAcceptsTheDocument(): void
    {
        $deal = $this->makeDeal();

        $searchable = $deal->toSearchableArray();

        $this->assertIsString($searchable['id']);
        $this->assertSame((string) $deal->getId(), $searchable['id']);
    }

    public function testSearchableArrayCarriesTheTenantFieldsTheEngineFiltersOn(): void
    {
        $deal = $this->makeDeal();

        $searchable = $deal->toSearchableArray();

        $this->assertSame((int) $deal->apps_id, $searchable['apps_id']);
        $this->assertSame((int) $deal->companies_id, $searchable['companies_id']);

        $facets = [];
        foreach ($deal->typesenseCollectionSchema()['fields'] as $field) {
            $facets[$field['name']] = $field['facet'] ?? false;
        }

        $this->assertTrue($facets['apps_id'], 'apps_id must be facetable or filter_by cannot scope by app');
        $this->assertTrue($facets['companies_id'], 'companies_id must be facetable or filter_by cannot scope by company');
    }

    public function testContactNameIsFlattenedIntoTheDocumentForNameMatching(): void
    {
        $people = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(static::$cachedUser->getCurrentCompany()->getId())
            ->create(['firstname' => 'Jorgelinauniq', 'lastname' => 'Duranuniq']);

        $deal = $this->makeDeal(['people_id' => $people->getId()]);

        $searchable = $deal->fresh()->toSearchableArray();

        $this->assertSame('Jorgelinauniq', $searchable['people_firstname']);
        $this->assertSame('Duranuniq', $searchable['people_lastname']);
        $this->assertStringContainsString('Duranuniq', $searchable['people_name']);
    }

    public function testDeletedDealsAreNotSearchable(): void
    {
        $deal = $this->makeDeal();
        $this->assertTrue($deal->shouldBeSearchable());

        $deal->is_deleted = 1;

        $this->assertFalse($deal->shouldBeSearchable());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeDeal(array $attributes = []): Deal
    {
        $user = auth()->user();

        $created = new CreateDealTool(app(Apps::class), $user->getCurrentCompany(), $user)
            ->__invoke(title: 'Deal ' . uniqid());

        /** @var Deal $deal */
        $deal = Deal::getById((int) $created['deal_id']);

        if ($attributes !== []) {
            $deal->forceFill($attributes)->saveOrFail();
        }

        return $deal;
    }
}
