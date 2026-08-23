<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class EvaluateProductDiscoveryCommandTest extends TestCase
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

    public function testScoresAJudgedSetAndReportsRecall(): void
    {
        $product = $this->makeProduct('Reloj de lujo');

        $file = $this->goldenSet([
            ['query' => 'reloj', 'relevant_product_ids' => [$product->getId()]],
        ]);

        $this->artisan('kanvas-inventory:evaluate-product-discovery', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $this->company()->getId(),
            '--file' => $file,
        ])
            ->expectsOutputToContain('recall@10=1.000')
            ->assertExitCode(0);
    }

    public function testReportsZeroWhenNothingRelevantSurfaces(): void
    {
        $this->makeProduct('Reloj de lujo');

        $file = $this->goldenSet([
            ['query' => 'zzzzznotacatalogword', 'relevant_product_ids' => [999999999]],
        ]);

        $this->artisan('kanvas-inventory:evaluate-product-discovery', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $this->company()->getId(),
            '--file' => $file,
        ])
            ->expectsOutputToContain('recall@10=0.000')
            ->assertExitCode(0);
    }

    public function testFailsTheRunWhenRecallIsBelowTheThreshold(): void
    {
        $this->makeProduct('Reloj de lujo');

        $file = $this->goldenSet([
            ['query' => 'zzzzznotacatalogword', 'relevant_product_ids' => [999999999]],
        ]);

        // The non-zero exit is what lets this gate a change rather than just
        // print a number nobody reads.
        $this->artisan('kanvas-inventory:evaluate-product-discovery', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $this->company()->getId(),
            '--file' => $file,
            '--min-recall' => 0.5,
        ])->assertExitCode(1);
    }

    public function testRejectsAnUnreadableGoldenSet(): void
    {
        $this->artisan('kanvas-inventory:evaluate-product-discovery', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $this->company()->getId(),
            '--file' => '/tmp/does-not-exist-golden-set.json',
        ])->assertExitCode(1);
    }

    public function testRejectsAGoldenSetWithNoUsableCases(): void
    {
        $file = $this->goldenSet([['query' => 'reloj']]);

        $this->artisan('kanvas-inventory:evaluate-product-discovery', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $this->company()->getId(),
            '--file' => $file,
        ])->assertExitCode(1);
    }

    private function goldenSet(array $cases): string
    {
        $file = sys_get_temp_dir() . '/golden-' . uniqid() . '.json';
        file_put_contents($file, json_encode(['cases' => $cases]));
        $this->tempFiles[] = $file;

        return $file;
    }

    private function company(): mixed
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
            ->create(['name' => $name, 'is_published' => 1, 'is_deleted' => 0]);

        return $product;
    }
}
