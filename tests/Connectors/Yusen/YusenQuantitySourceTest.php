<?php

declare(strict_types=1);

namespace Tests\Connectors\Yusen;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Yusen\Actions\BuildYusenDiscrepancyReportAction;
use Kanvas\Connectors\Yusen\Contracts\InventoryQuantitySource;
use Kanvas\Connectors\Yusen\Enums\DiscrepancyTypeEnum;
use Kanvas\Connectors\Yusen\Services\InventoryBalanceXmlParser;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Tests\TestCase;

/**
 * The comparator is meant to be source-agnostic — these drive it with sources that have nothing
 * to do with Kanvas or NetSuite, which is the only way to prove that claim.
 */
class YusenQuantitySourceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    private Apps $kanvasApp;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;
    }

    public function testAThirdPartySourceNeedsNoChangeToTheComparator(): void
    {
        $source = $this->source('acumatica', [
            // matches the file exactly — no row expected
            '9990000000014' => 110.0,
            // file says 1000 — 400 short
            '9990000000045' => 600.0,
            // stock the source holds that Yusen never sent
            '7777777777777' => 12.0,
        ]);

        $report = $this->runWith([$source]);

        // 1 mismatch + 2 items the source never heard of + 1 it holds that Yusen didn't send.
        $this->assertSame(['acumatica' => 4], $report['by_source']);

        $mismatch = $this->rowFor($report, '9990000000045');
        $this->assertSame(DiscrepancyTypeEnum::QUANTITY_MISMATCH->value, $mismatch['type']);
        $this->assertSame(400.0, $mismatch['difference']);

        // No dedicated MISSING_IN_ACUMATICA case exists, so unknown sources fall back.
        $this->assertSame(
            DiscrepancyTypeEnum::MISSING_IN_SOURCE->value,
            $this->rowFor($report, '9990000065686')['type']
        );

        $absent = $this->rowFor($report, '7777777777777');
        $this->assertSame(DiscrepancyTypeEnum::MISSING_IN_YUSEN->value, $absent['type']);
        $this->assertSame(12.0, $absent['compared_quantity']);
        $this->assertNull($absent['yusen_quantity']);
    }

    public function testEachSourceIsReportedSeparately(): void
    {
        $report = $this->runWith([
            $this->source('alpha', ['9990000000045' => 600.0]),
            $this->source('beta', ['9990000000045' => 1000.0]),
        ]);

        // alpha disagrees on the item plus the 3 it never heard of; beta agrees on the item.
        $this->assertSame(4, $report['by_source']['alpha']);
        $this->assertSame(3, $report['by_source']['beta']);
    }

    public function testAFailingSourceDoesNotDiscardTheOnesThatWorked(): void
    {
        $report = $this->runWith([
            $this->source('alpha', ['9990000000045' => 600.0]),
            $this->source('flaky', [], throws: 'upstream exploded'),
        ]);

        $this->assertSame(4, $report['by_source']['alpha']);
        $this->assertArrayNotHasKey('flaky', $report['by_source']);
        $this->assertSame('upstream exploded', $report['source_errors']['flaky']);
    }

    public function testToleranceSuppressesSmallDeltasOnAnySource(): void
    {
        $this->user->getCurrentCompany()->set('yusen_quantity_tolerance', 500);

        $report = $this->runWith([$this->source('alpha', ['9990000000045' => 600.0])]);

        $this->assertNull($this->rowFor($report, '9990000000045'));
    }

    private function runWith(array $sources): array
    {
        $balance = new InventoryBalanceXmlParser()->parseFile(__DIR__ . '/fixtures/item-balance.xml');

        return new BuildYusenDiscrepancyReportAction(
            $this->kanvasApp,
            $this->user->getCurrentCompany(),
            $balance,
            $sources,
        )->execute();
    }

    private function rowFor(array $report, string $item): ?array
    {
        foreach ($report['rows'] as $row) {
            if ($row['item'] === $item) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, float> $quantities
     * @param string|null $throws message to fail with instead of answering, for the outage case
     */
    private function source(string $key, array $quantities, ?string $throws = null): InventoryQuantitySource
    {
        return new class ($key, $quantities, $throws) implements InventoryQuantitySource {
            public function __construct(
                private readonly string $key,
                private readonly array $quantities,
                private readonly ?string $throws,
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function quantities(): array
            {
                if ($this->throws !== null) {
                    throw new RuntimeException($this->throws);
                }

                return $this->quantities;
            }

            public function describe(string $item): ?string
            {
                return 'Item ' . $item;
            }
        };
    }
}
