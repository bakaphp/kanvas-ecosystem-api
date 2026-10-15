<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Baka\Contracts\CompanyInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Services\LeadCatalogResolver;
use Tests\TestCase;

final class LeadCatalogResolverTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testANullNameKeepsTheCurrentTypeAndSource(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(42, $resolver->typeId(null, 42));
        $this->assertSame(0, $resolver->typeId(null));
        $this->assertSame(7, $resolver->sourceId(null, 7));
        $this->assertSame(0, $resolver->sourceId(null));
    }

    public function testATypeNameIsCreatedOnceAndReusedAfterwards(): void
    {
        $resolver = $this->resolver();
        $name = $this->uniqueName('Walk-In');

        $first = $resolver->typeId($name, null, 'Reynolds ProspectType: ' . $name);
        $second = $resolver->typeId($name);

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, $second);

        $row = LeadType::query()->where('name', $name)->get();
        $this->assertCount(1, $row);
        $this->assertSame('Reynolds ProspectType: ' . $name, $row->first()->description);
        $this->assertSame(1, (int) $row->first()->is_active);
        $this->assertSame($this->company()->getId(), (int) $row->first()->companies_id);
    }

    public function testADescriptionDefaultsToTheName(): void
    {
        $name = $this->uniqueName('Internet');

        $id = $this->resolver()->sourceId($name);

        $this->assertSame($name, LeadSource::query()->where('id', $id)->first()->description);
    }

    public function testASourceNameIsCreatedOnceAndReusedAfterwards(): void
    {
        $resolver = $this->resolver();
        $name = $this->uniqueName('Cars.com');

        $first = $resolver->sourceId($name, null, 'Reynolds ProviderName: ' . $name);

        $this->assertSame($first, $resolver->sourceId($name));
        $this->assertCount(1, LeadSource::query()->where('name', $name)->get());
    }

    public function testAStatusNameIsCreatedOnceAndNotMadeTheDefault(): void
    {
        $resolver = $this->resolver();
        $name = $this->uniqueName('Active');

        $first = $resolver->statusId($name);

        $this->assertSame($first, $resolver->statusId($name));

        $rows = LeadStatus::query()->where('name', $name)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows->first()->is_default);
    }

    public function testANullStatusKeepsTheCurrentOne(): void
    {
        $this->assertSame(99, $this->resolver()->statusId(null, 99));
    }

    /**
     * leads_status_id = 0 breaks LeadObserver, which calls status()->firstOrFail() on save.
     */
    public function testANullStatusWithNoCurrentOneFallsBackToAStatusVisibleToTheApp(): void
    {
        $app = app(Apps::class);
        $expected = LeadStatus::query()->whereIn('apps_id', [0, $app->getId()])->first();

        $id = $this->resolver()->statusId(null);

        $this->assertSame($expected?->getId() ?? 0, $id);
    }

    private function resolver(): LeadCatalogResolver
    {
        return new LeadCatalogResolver(app(Apps::class), $this->company());
    }

    private function company(): CompanyInterface
    {
        return auth()->user()->getCurrentCompany();
    }

    private function uniqueName(string $base): string
    {
        return $base . ' ' . uniqid('', true);
    }
}
