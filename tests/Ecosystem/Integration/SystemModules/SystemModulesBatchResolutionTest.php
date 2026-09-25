<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\SystemModules;

use Illuminate\Cache\Events\CacheFlushing;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\UserFullTableName;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class SystemModulesBatchResolutionTest extends TestCase
{
    public function testItCreatesTheModulesItIsMissing(): void
    {
        $app = app(Apps::class);
        $names = [
            'Tests\\Batch\\Alpha' . uniqid(),
            'Tests\\Batch\\Beta' . uniqid(),
        ];

        $modules = SystemModulesRepository::getByModelNames($names, $app);

        foreach ($names as $name) {
            $this->assertTrue($modules->has($name));
            $this->assertSame($name, $modules->get($name)->model_name);
            $this->assertSame((int) $app->getKey(), (int) $modules->get($name)->apps_id);
        }
    }

    /**
     * The bulk insert bypasses UuidTrait and bootSlugTrait, so these columns have to be written by
     * hand — and `name`/`created_at` are NOT NULL with no default.
     */
    public function testTheBulkInsertFillsTheColumnsTheEloquentHooksWouldHave(): void
    {
        $app = app(Apps::class);
        $name = 'Tests\\Batch\\Gamma' . uniqid();

        $module = SystemModulesRepository::getByModelNames([$name], $app)->get($name);
        $single = SystemModulesRepository::getByModelName($name, $app);

        $this->assertNotEmpty($module->uuid);
        $this->assertNotEmpty($module->slug);
        $this->assertNotEmpty($module->name);
        $this->assertNotNull($module->created_at);
        $this->assertSame($single->getId(), $module->getId(), 'the batch row must be the one getByModelName finds');
        $this->assertSame($single->slug, $module->slug);
        $this->assertSame($single->name, $module->name);
    }

    public function testItReusesExistingRowsInsteadOfDuplicatingThem(): void
    {
        $app = app(Apps::class);
        $name = 'Tests\\Batch\\Delta' . uniqid();

        $first = SystemModulesRepository::getByModelNames([$name], $app)->get($name);
        $second = SystemModulesRepository::getByModelNames([$name, $name], $app)->get($name);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame(
            1,
            SystemModules::where('model_name', $name)->where('apps_id', $app->getKey())->count()
        );
    }

    public function testItResolvesTheUserFullTableNameAliasToTheUsersModule(): void
    {
        $app = app(Apps::class);

        $modules = SystemModulesRepository::getByModelNames([UserFullTableName::class], $app);

        $this->assertTrue($modules->has(UserFullTableName::class));
        $this->assertSame(Users::class, $modules->get(UserFullTableName::class)->model_name);
        $this->assertSame(
            SystemModulesRepository::getByModelName(UserFullTableName::class, $app)->getId(),
            $modules->get(UserFullTableName::class)->getId()
        );
    }

    /**
     * The whole point of the batch path: cache invalidation must not scale with the number of rows.
     * Resolving them one at a time costs one flush per row, which is what made createApp take 8s.
     */
    public function testInvalidationDoesNotScaleWithTheNumberOfRowsWritten(): void
    {
        $app = app(Apps::class);

        $count = function (array $names) use ($app): int {
            $flushes = 0;
            $listener = function () use (&$flushes) {
                $flushes++;
            };
            Event::listen(CacheFlushing::class, $listener);
            SystemModulesRepository::getByModelNames($names, $app);
            Event::forget(CacheFlushing::class);

            return $flushes;
        };

        $one = $count(['Tests\\Batch\\Flush' . uniqid()]);

        $many = [];
        for ($i = 0; $i < 6; $i++) {
            $many[] = 'Tests\\Batch\\Flush' . $i . uniqid();
        }

        $this->assertGreaterThan(0, $one);
        $this->assertSame($one, $count($many), 'six new rows must cost the same invalidation as one');
        $this->assertSame(0, $count($many), 'resolving rows that already exist must not invalidate at all');
    }

    public function testEmptyInputDoesNotTouchTheDatabase(): void
    {
        $this->assertTrue(SystemModulesRepository::getByModelNames([], app(Apps::class))->isEmpty());
    }
}
