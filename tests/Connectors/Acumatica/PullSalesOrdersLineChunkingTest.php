<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Actions\PullSalesOrdersAction;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

class PullSalesOrdersLineChunkingTest extends TestCase
{
    private const ACUMATICA_COMPANY_ID = 2;

    /**
     * SOLine has millions of rows and the sync fans every order number into a single IN(),
     * which blew past SQL Server's 2100 bound-parameter ceiling. fetchLinesByOrder must chunk
     * the IN() and merge the grouped result back together across chunk boundaries.
     */
    public function testFetchLinesByOrderChunksAndMergesAllOrders(): void
    {
        $chunkSize = 100;
        $orderCount = 250; // 3 chunks: 100 + 100 + 50
        $connection = $this->seededConnection($orderCount);

        $action = $this->makeAction($connection, $chunkSize);

        $orderNbrs = array_map(static fn (int $i): string => 'SO-' . $i, range(1, $orderCount));

        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $grouped = $action->fetchLinesByOrderPublic($orderNbrs);
        $queries = $connection->getQueryLog();

        // Every order came back, correctly keyed and un-truncated by the chunking.
        $this->assertCount($orderCount, $grouped);

        foreach (range(1, $orderCount) as $i) {
            $key = 'SO-SO-' . $i;
            $this->assertArrayHasKey($key, $grouped);
            $this->assertCount(1, $grouped[$key]);
            $this->assertSame('SKU-1', $grouped[$key][0]['sku']);
            $this->assertSame('MAIN', $grouped[$key][0]['warehouse']);
        }

        // The merge must be driven by multiple bounded queries — one un-chunked IN() is the bug.
        $this->assertCount((int) ceil($orderCount / $chunkSize), $queries);
    }

    public function testFetchLinesByOrderReturnsEmptyWithoutHittingTheDatabase(): void
    {
        $connection = $this->seededConnection(1);
        $action = $this->makeAction($connection, 100);

        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->assertSame([], $action->fetchLinesByOrderPublic([]));
        $this->assertCount(0, $connection->getQueryLog());
    }

    private function makeAction(ConnectionInterface $connection, int $chunkSize): PullSalesOrdersAction
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $region = Regions::firstWhere('apps_id', $app->getId()) ?? Regions::first();

        return new class (
            $app,
            $company,
            auth()->user(),
            $region,
            self::ACUMATICA_COMPANY_ID,
            $connection,
            $chunkSize,
        ) extends PullSalesOrdersAction {
            public function __construct(
                Apps $app,
                $company,
                $user,
                $region,
                int $acumaticaCompanyId,
                private readonly ConnectionInterface $testConnection,
                int $chunkSize,
            ) {
                parent::__construct($app, $company, $user, $region, $acumaticaCompanyId);
                $this->lineFetchChunkSize = $chunkSize;
            }

            protected function readConnection(): ConnectionInterface
            {
                return $this->testConnection;
            }

            /**
             * @param array<int, string> $orderNbrs
             *
             * @return array<string, array<int, array<array-key, mixed>>>
             */
            public function fetchLinesByOrderPublic(array $orderNbrs): array
            {
                return $this->fetchLinesByOrder($orderNbrs);
            }
        };
    }

    private function seededConnection(int $orderCount): ConnectionInterface
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'acumatica_chunk_test');

        $connection = $capsule->getConnection('acumatica_chunk_test');
        $schema = $connection->getSchemaBuilder();

        $schema->create('SOLine', function ($table): void {
            $table->string('OrderType');
            $table->string('OrderNbr');
            $table->integer('InventoryID');
            $table->integer('SiteID');
            $table->integer('CompanyID');
            $table->string('TranDesc')->nullable();
            $table->integer('OrderQty')->default(0);
            $table->integer('ShippedQty')->default(0);
            $table->float('UnitPrice')->default(0);
            $table->float('DiscAmt')->default(0);
        });

        $schema->create('InventoryItem', function ($table): void {
            $table->integer('InventoryID');
            $table->integer('CompanyID');
            $table->string('InventoryCD');
        });

        $schema->create('INSite', function ($table): void {
            $table->integer('SiteID');
            $table->integer('CompanyID');
            $table->string('SiteCD');
        });

        $connection->table('InventoryItem')->insert([
            'InventoryID' => 1,
            'CompanyID' => self::ACUMATICA_COMPANY_ID,
            'InventoryCD' => 'SKU-1',
        ]);
        $connection->table('INSite')->insert([
            'SiteID' => 1,
            'CompanyID' => self::ACUMATICA_COMPANY_ID,
            'SiteCD' => 'MAIN',
        ]);

        $lines = array_map(static fn (int $i): array => [
            'OrderType' => 'SO',
            'OrderNbr' => 'SO-' . $i,
            'InventoryID' => 1,
            'SiteID' => 1,
            'CompanyID' => self::ACUMATICA_COMPANY_ID,
            'TranDesc' => 'Line ' . $i,
            'OrderQty' => 1,
            'ShippedQty' => 1,
            'UnitPrice' => 9.99,
            'DiscAmt' => 0,
        ], range(1, $orderCount));

        foreach (array_chunk($lines, 200) as $batch) {
            $connection->table('SOLine')->insert($batch);
        }

        return $connection;
    }
}
