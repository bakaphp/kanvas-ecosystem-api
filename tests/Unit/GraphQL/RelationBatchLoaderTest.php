<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL;

use App\GraphQL\Execution\BatchLoader\RelationBatchLoader;
use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Nuwave\Lighthouse\Execution\ModelsLoader\ModelsLoader;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for Sentry KANVAS-ECOSYSTEM-631:
 * "Undefined array key Kanvas\Inventory\Variants\Models\Variants:NNN" thrown from the
 * RelationBatchLoader when a batched relation (variant.product @belongsTo) sits below nested
 * paginated / deferred fields and parents therefore register across more than one resolution wave.
 */
final class RelationBatchLoaderTest extends TestCase
{
    public function testResolvesParentRegisteredAfterAnEarlierWaveAlreadyResolved(): void
    {
        $modelsLoader = $this->recordingModelsLoader();
        $loader = new RelationBatchLoader($modelsLoader);

        // Wave 1: register the first parent and drain its deferred — this triggers resolve().
        $firstResult = $this->resolveDeferred($loader->load($this->makeModel(1)));
        $this->assertSame('relation:1', $firstResult);

        // Wave 2: a parent that arrives only *after* wave 1 resolved. The stock Lighthouse loader
        // locks after the first resolve and this read throws "Undefined array key ...:2".
        $secondResult = $this->resolveDeferred($loader->load($this->makeModel(2)));
        $this->assertSame('relation:2', $secondResult);

        // One batch query per wave, never per parent.
        $this->assertSame([[1], [2]], $modelsLoader->loadedKeys);
    }

    public function testBatchesEveryParentRegisteredWithinASingleWave(): void
    {
        $modelsLoader = $this->recordingModelsLoader();
        $loader = new RelationBatchLoader($modelsLoader);

        $deferredA = $loader->load($this->makeModel(10));
        $deferredB = $loader->load($this->makeModel(20));

        $this->assertSame('relation:10', $this->resolveDeferred($deferredA));
        $this->assertSame('relation:20', $this->resolveDeferred($deferredB));

        // Both parents loaded in a single batch — no per-parent (N+1) queries.
        $this->assertSame([[10, 20]], $modelsLoader->loadedKeys);
    }

    private function resolveDeferred(Deferred $deferred): mixed
    {
        $result = null;
        $deferred->then(function ($value) use (&$result): void {
            $result = $value;
        });

        SyncPromise::runQueue();

        return $result;
    }

    private function makeModel(int $id): Model
    {
        $model = new class () extends Model {
            protected $guarded = [];
        };
        $model->id = $id;

        return $model;
    }

    private function recordingModelsLoader(): ModelsLoader
    {
        return new class () implements ModelsLoader {
            /** @var array<int, array<int, int|string|null>> */
            public array $loadedKeys = [];

            public function load(EloquentCollection $parents): void
            {
                $this->loadedKeys[] = $parents->map(static fn (Model $model): mixed => $model->getKey())->values()->all();

                foreach ($parents as $model) {
                    $model->setRelation('rel', 'relation:' . $model->getKey());
                }
            }

            public function extract(Model $model): mixed
            {
                return $model->getRelation('rel');
            }
        };
    }
}
