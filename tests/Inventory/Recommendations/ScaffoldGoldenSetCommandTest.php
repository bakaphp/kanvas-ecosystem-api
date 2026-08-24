<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Models\RecommendationImpression;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ScaffoldGoldenSetCommandTest extends TestCase
{
    use DatabaseTransactions;
    use PinsSearchEngine;

    protected $connectionsToTransact = [null, 'inventory'];

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinSearchEngine();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        $this->restoreSearchEngine();

        parent::tearDown();
    }

    public function testDraftsCasesFromTheImpressionLogPreFilledWithTodaysResults(): void
    {
        $product = $this->makeProduct('Reloj de lujo');
        $this->logImpression('Reloj de lujo elegante');

        $file = $this->outputPath();

        $this->artisan('kanvas-inventory:scaffold-golden-set', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $this->company()->getId(),
            '--out' => $file,
        ])->assertExitCode(0);

        $written = json_decode((string) file_get_contents($file), true);

        $this->assertSame('Reloj de lujo elegante', $written['cases'][0]['query']);
        $this->assertContains($product->getId(), $written['cases'][0]['relevant_product_ids']);
        $this->assertTrue($written['cases'][0]['unjudged'], 'Scaffolded cases must be flagged so evaluate refuses to score them.');
    }

    public function testKeepsAQueryThatReturnsNothing(): void
    {
        $this->logImpression('zzzzznotacatalogword');

        $file = $this->outputPath();

        $this->artisan('kanvas-inventory:scaffold-golden-set', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $this->company()->getId(),
            '--out' => $file,
        ])->assertExitCode(0);

        $written = json_decode((string) file_get_contents($file), true);

        // A query that finds nothing is the most useful case in the set — it is
        // either a catalog gap or a blurb that failed, and both are fixable.
        $this->assertSame([], $written['cases'][0]['relevant_product_ids']);
    }

    public function testFailsWhenThereIsNothingToDraftFrom(): void
    {
        $this->artisan('kanvas-inventory:scaffold-golden-set', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $this->company()->getId(),
            '--out' => $this->outputPath(),
        ])->assertExitCode(1);
    }

    public function testAcceptsQueriesDirectlyWithoutAnImpressionLog(): void
    {
        $product = $this->makeProduct('Reloj de lujo');
        $file = $this->outputPath();

        $this->artisan('kanvas-inventory:scaffold-golden-set', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $this->company()->getId(),
            '--out' => $file,
            '--query' => ['reloj'],
        ])->assertExitCode(0);

        $written = json_decode((string) file_get_contents($file), true);

        $this->assertSame('reloj', $written['cases'][0]['query']);
        $this->assertContains($product->getId(), $written['cases'][0]['relevant_product_ids']);
    }

    private function outputPath(): string
    {
        $file = storage_path('app/golden-set-test-' . fake()->unique()->uuid() . '.json');
        $this->tempFiles[] = $file;

        return $file;
    }

    private function logImpression(string $query): void
    {
        RecommendationImpression::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->company()->getId(),
            'recommendation_uuid' => fake()->unique()->uuid(),
            'query_raw' => $query,
            'query_normalized' => mb_strtolower($query),
            'product_ids' => [],
            'results_count' => 0,
        ]);
    }

    private function company()
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user->getCurrentCompany();
    }

    private function makeProduct(string $name): Products
    {
        /** @var Products $product */
        $product = Products::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company()->getId())
            ->create([
                'name' => $name,
                'is_published' => 1,
                'is_deleted' => 0,
            ]);

        return $product;
    }
}
