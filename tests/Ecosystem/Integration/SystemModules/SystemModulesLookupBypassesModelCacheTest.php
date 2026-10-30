<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\SystemModules;

use Baka\Support\Str;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Tests\TestCase;

/**
 * SystemModules is no longer Cachable, and this is what keeps it that way: CI runs with the model
 * cache off, so it is switched on here, and the lookups below only stay correct while the model
 * ignores it. Real case: the Companies module lookup for a new app answered with app 1's row, then
 * with nothing while the row was in the table, which filed a company's knowledge upload where no
 * reader could see it and inserted a duplicate module beside the real one.
 */
final class SystemModulesLookupBypassesModelCacheTest extends TestCase
{
    /** @var list<string> */
    private array $probeNames = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['laravel-model-caching.enabled' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->probeNames !== []) {
            self::table()->whereIn('model_name', $this->probeNames)->delete();
        }

        parent::tearDown();
    }

    public function testFindsARowInsertedBehindTheCacheInsteadOfDuplicatingIt(): void
    {
        $app = app(Apps::class);
        $name = $this->probeName();

        // The resolver's own query shape, so a cache would hold "no such row" under exactly its key.
        $this->assertNull(
            SystemModules::query()->where('model_name', $name)->where('apps_id', $app->getKey())->orderBy('id')->first()
        );

        $inserted = $this->insertRaw($name, (int) $app->getKey());

        $this->assertSame($inserted, SystemModulesRepository::getByModelName($name, $app)->getId());
        $this->assertSame(1, $this->rawCount($name));
    }

    public function testDuplicatesResolveToTheLowestIdEverywhere(): void
    {
        $app = app(Apps::class);
        $name = $this->probeName();
        $lowest = $this->insertRaw($name, (int) $app->getKey());
        $this->insertRaw($name, (int) $app->getKey());

        $this->assertSame($lowest, SystemModulesRepository::getByModelName($name, $app)->getId());
        $this->assertSame($lowest, SystemModulesRepository::getByModelNames([$name], $app)->get($name)->getId());
    }

    public function testEachAppGetsItsOwnRow(): void
    {
        $app = app(Apps::class);
        // Only the key is read, and system_modules.apps_id has no foreign key, so no app row is needed.
        $other = new Apps();
        $other->id = (int) $app->getKey() + 1_000_000;
        $name = $this->probeName();

        $mine = SystemModulesRepository::getByModelName($name, $app);
        $theirs = SystemModulesRepository::getByModelName($name, $other);

        $this->assertNotSame($mine->getId(), $theirs->getId());
        $this->assertSame((int) $app->getKey(), (int) $mine->apps_id);
        $this->assertSame((int) $other->getKey(), (int) $theirs->apps_id);
    }

    private function probeName(): string
    {
        $name = 'Tests\\ModelCache\\Probe' . uniqid();
        $this->probeNames[] = $name;

        return $name;
    }

    private function insertRaw(string $name, int $appId): int
    {
        return (int) self::table()->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'model_name' => $name,
            'name' => Str::simpleSlug($name),
            'slug' => Str::simpleSlug($name),
            'apps_id' => $appId,
            'created_at' => Carbon::now(),
        ]);
    }

    private function rawCount(string $name): int
    {
        return self::table()->where('model_name', $name)->count();
    }

    /** Raw rows, so inserts and counts fire no Eloquent event the model could hook. */
    private static function table(): Builder
    {
        return new SystemModules()->getConnection()->table('system_modules');
    }
}
