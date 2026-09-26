<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\AccessControlList;

use Bouncer;
use Illuminate\Support\Facades\DB;
use Kanvas\AccessControlList\Actions\CreateAbilitiesByModule;
use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Actions\CreateRolesByTemplatesAction;
use Kanvas\AccessControlList\Actions\ResolveAbilitiesAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Ability;
use Kanvas\AccessControlList\Templates\ModulesRepositories;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

final class BulkAbilityGrantsTest extends TestCase
{
    public function testItCreatesTheAbilitiesItIsMissingAndReusesTheOnesItFinds(): void
    {
        $this->scopeToNewApp();
        $name = 'probe-' . uniqid();

        $first = new ResolveAbilitiesAction()->execute([[$name, Apps::class]]);
        $second = new ResolveAbilitiesAction()->execute([[$name, Apps::class]]);

        $key = ResolveAbilitiesAction::key($name, Apps::class);
        $this->assertTrue($first->has($key));
        $this->assertSame($first->get($key)->getKey(), $second->get($key)->getKey());
        $this->assertSame(1, Ability::query()->where('name', $name)->count());
    }

    public function testTheBulkInsertWritesTheScopeBouncersCreatingHookWouldHave(): void
    {
        $app = $this->scopeToNewApp();
        $name = 'probe-scope-' . uniqid();

        $ability = new ResolveAbilitiesAction()->execute([[$name, Apps::class]])
            ->get(ResolveAbilitiesAction::key($name, Apps::class));

        $this->assertSame(RolesEnums::getScope($app), $ability->scope);
    }

    public function testItKeepsAnExplicitTitleAndDefaultsToTheAbilityName(): void
    {
        $this->scopeToNewApp();
        $named = 'probe-title-' . uniqid();
        $plain = 'probe-plain-' . uniqid();

        $resolved = new ResolveAbilitiesAction()->execute([
            [$named, null, 'A Custom Title'],
            [$plain, null],
        ]);

        $this->assertSame('A Custom Title', $resolved->get(ResolveAbilitiesAction::key($named, null))->title);
        $this->assertSame(ucfirst($plain), $resolved->get(ResolveAbilitiesAction::key($plain, null))->title);
    }

    public function testDuplicatePairsResolveToASingleAbility(): void
    {
        $this->scopeToNewApp();
        $name = 'probe-dupe-' . uniqid();

        $resolved = new ResolveAbilitiesAction()->execute([
            [$name, Apps::class],
            [$name, Apps::class],
        ]);

        $this->assertCount(1, $resolved);
        $this->assertSame(1, Ability::query()->where('name', $name)->count());
    }

    public function testEmptyInputResolvesToNothing(): void
    {
        $this->scopeToNewApp();

        $this->assertTrue(new ResolveAbilitiesAction()->execute([])->isEmpty());
    }

    /**
     * The whole point of the batch: grants must not cost a query per ability. The old shape was
     * ~5 queries per (ability, model) pair, 186 pairs per role.
     */
    public function testGrantingEveryAbilityToEveryRoleStaysAConstantNumberOfQueries(): void
    {
        $app = $this->scopeToNewApp();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        new CreateAbilitiesByModule($app)->execute();
        new CreateRolesByTemplatesAction($app)->execute();

        $pairs = 0;
        foreach (ModulesRepositories::getAllAbilities() as $abilities) {
            $pairs += count($abilities);
        }

        $this->assertGreaterThan(100, $pairs, 'guard: the ability map should be large enough for this to mean something');
        $this->assertLessThan($pairs, $queries, 'the run must cost fewer queries than there are ability/model pairs');
    }

    public function testItGrantsEveryAbilityInTheTemplateAndIsIdempotent(): void
    {
        $app = $this->scopeToNewApp();

        new CreateAbilitiesByModule($app)->execute();
        new CreateRolesByTemplatesAction($app)->execute();
        $first = $this->snapshot($app);

        new CreateAbilitiesByModule($app)->execute();
        new CreateRolesByTemplatesAction($app)->execute();

        $this->assertSame($first, $this->snapshot($app), 'a second run must not add or change a row');

        $pairs = 0;
        foreach (ModulesRepositories::getAllAbilities() as $abilities) {
            $pairs += count($abilities);
        }
        $this->assertSame($pairs, $first['abilities_modules']);

        // Admin and Users each get the full set; Owner is granted everything, which is one wildcard row.
        $this->assertSame($pairs * 2 + 1, $first['permissions']);
    }

    private function scopeToNewApp(): Apps
    {
        $app = new Apps();
        $app->fill([
            'name' => 'BulkGrants ' . uniqid(),
            'url' => 'https://bulk.test',
            'description' => 'test',
            'domain' => 'bulk.test',
            'is_actived' => 1,
            'ecosystem_auth' => 1,
            'payments_active' => 0,
            'is_public' => 0,
            'domain_based' => 0,
        ]);
        $app->saveOrFail();

        foreach ([RolesEnums::OWNER->value, RolesEnums::ADMIN->value, RolesEnums::USER->value] as $role) {
            new CreateRoleAction($role, $role, $app)->execute();
        }

        Bouncer::scope()->to(RolesEnums::getScope($app));

        return $app;
    }

    /**
     * @return array<string, int>
     */
    private function snapshot(Apps $app): array
    {
        $scope = RolesEnums::getScope($app);

        return [
            'abilities' => Ability::query()->where('scope', $scope)->count(),
            'abilities_modules' => DB::connection('ecosystem')->table('abilities_modules')
                ->where('apps_id', $app->getKey())->where('scope', $scope)->count(),
            'permissions' => DB::connection('ecosystem')->table('permissions')
                ->where('scope', $scope)->where('entity_type', 'roles')->count(),
        ];
    }
}
