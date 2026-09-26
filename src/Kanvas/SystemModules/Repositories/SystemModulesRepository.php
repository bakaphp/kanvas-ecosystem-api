<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\SystemModules\Contracts\SystemModuleInputInterface;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\UserFullTableName;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Override;
use Ramsey\Uuid\Uuid;

class SystemModulesRepository
{
    use SearchableTrait;

    #[Override]
    public static function getModel(): Model
    {
        return new SystemModules();
    }

    public static function getByModelName(string $modelName, ?AppInterface $app = null): SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;

        $modelName = self::normalizeModelName($modelName);

        return SystemModules::firstOrCreate(
            [
                'model_name' => $modelName,
                'apps_id' => $app->getKey(),
            ],
            [
                'slug' => Str::simpleSlug($modelName),
            ]
        );
    }

    /**
     * Resolve many model names in one round trip, keyed by model name.
     *
     * App creation resolves ~46 models at once. A firstOrCreate apiece costs 46 round trips and, since
     * the model is cached, 46 tag flushes on top. This is two queries and one bulk insert however many
     * models are asked for, and the insert goes through the query builder deliberately so it fires no
     * per-row Eloquent events — which also means UuidTrait and bootSlugTrait do not run, hence the
     * explicit uuid/slug/name.
     */
    public static function getByModelNames(array $modelNames, ?AppInterface $app = null): Collection
    {
        $app = $app === null ? app(Apps::class) : $app;

        $requested = array_values(array_unique($modelNames));
        $modelNames = array_values(array_unique(array_map(
            static fn (string $modelName): string => self::normalizeModelName($modelName),
            $requested
        )));

        if (empty($modelNames)) {
            return new Collection();
        }

        $modules = self::queryByModelNames($modelNames, $app);
        $missing = array_values(array_diff($modelNames, $modules->keys()->all()));

        if (empty($missing)) {
            return self::aliasRequestedNames($modules, $requested);
        }

        $now = Carbon::now();

        SystemModules::query()->insert(array_map(
            static fn (string $modelName): array => [
                'uuid' => (string) Str::uuid7(),
                'model_name' => $modelName,
                'name' => Str::simpleSlug($modelName),
                'slug' => Str::simpleSlug($modelName),
                'apps_id' => $app->getKey(),
                'created_at' => $now,
            ],
            $missing
        ));

        return self::aliasRequestedNames(self::queryByModelNames($modelNames, $app), $requested);
    }

    /**
     * The rows come back keyed by their own model_name, so a caller that asked for an aliased name
     * (UserFullTableName) would not find its entry. Add the alias back alongside the real key.
     */
    protected static function aliasRequestedNames(Collection $modules, array $requested): Collection
    {
        foreach ($requested as $modelName) {
            $normalized = self::normalizeModelName($modelName);

            if ($normalized !== $modelName && $modules->has($normalized)) {
                $modules->put($modelName, $modules->get($normalized));
            }
        }

        return $modules;
    }

    protected static function queryByModelNames(array $modelNames, AppInterface $app): Collection
    {
        return SystemModules::whereIn('model_name', $modelNames)
            ->where('apps_id', $app->getKey())
            ->get()
            ->keyBy('model_name');
    }

    /**
     * UserFullTableName is a read model over the same table as Users; it has no system module of
     * its own, so callers asking for it must land on the Users row.
     */
    protected static function normalizeModelName(string $modelName): string
    {
        return $modelName === UserFullTableName::class ? Users::class : $modelName;
    }

    /**
     * Every app registers its own system module per model, so files attached to a row shared by all
     * apps (a global catalog) are spread across these ids. A subquery, not a list, so a page of rows
     * doesn't re-read system_modules once per row.
     */
    public static function getIdsByModelNameFromAnyAppQuery(string $modelName): Builder
    {
        return SystemModules::query()
            ->select('id')
            ->where('model_name', $modelName);
    }

    /**
     * Get by name.
     */
    public static function getByName(string $name, ?AppInterface $app = null): SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;

        return SystemModules::where('name', self::normalizeModelName($name))
                                    ->where('apps_id', $app->getKey())
                                    ->firstOrFail();
    }

    /**
     * Get by slug
     */
    public static function getBySlug(string $slug, ?AppInterface $app = null): SystemModules
    {
        $app = $app === null ? app(Apps::class) : $app;

        return SystemModules::where('slug', $slug)
                                    ->where('apps_id', $app->getKey())
                                    ->firstOrFail();
    }

    /**
     * Get the entity from the input
     */
    public static function getEntityFromInput(
        SystemModuleInputInterface $entityInput,
        Users $user,
        bool $useCompanyReference = true
    ): Model {
        $systemModule = self::getByUuidOrModelName($entityInput->systemModuleUuid);
        $modelName = SystemModules::convertLegacySystemModules($systemModule->model_name);

        /**
        * @var BaseModel
        */
        $entityModel = new $modelName();
        $hasUuid = $entityModel->hasColumn('uuid');

        $isUser = $entityModel instanceof Users;
        $isCompany = $entityModel instanceof Companies;

        $hasAppId = $entityModel->hasColumn('apps_id');
        $hasCompanyId = $entityModel->hasColumn('companies_id');

        if (! $hasAppId && ! $hasCompanyId && (! $isUser && ! $isCompany)) {
            throw new InternalServerErrorException('This system module doesn\'t allow external custom fields');
        }
        $field = $hasUuid && Str::isUuid($entityInput->entityId) ? 'uuid' : 'id';

        if ($isUser || $isCompany) {
            $entity = $entityModel::where('uuid', $entityInput->entityId)
                    ->notDeleted()
                    ->firstOrFail();

            if ($user->isAppOwner()) {
                return $entity;
            }

            //check if the user belongs to the company
            if ($entity instanceof Users) {
                UsersRepository::belongsToCompany(
                    $entity,
                    $user->getCurrentCompany()
                );
            } elseif ($entity instanceof Companies) {
                UsersRepository::belongsToCompany(
                    $user,
                    $entity
                );
            }
        } else {
            if ($user->isAppOwner() || ! $useCompanyReference) {
                $entity = $entityModel::where($field, $entityInput->entityId)
                        ->fromApp()
                        ->notDeleted()
                        ->firstOrFail();
            } else {
                $entity = $entityModel::where($field, $entityInput->entityId)
                        ->fromApp()
                        ->fromCompany($user->getCurrentCompany())
                        ->notDeleted()
                        ->firstOrFail();
            }
        }

        return $entity;
    }

    /**
     * Get System Module by its uuid or model_name.
     */
    public static function getByUuidOrModelName(string $uuidOrModelName, ?AppInterface $app = null): SystemModules
    {
        $systemModuleSearchField = Uuid::isValid($uuidOrModelName) ? 'uuid' : 'model_name';

        /**
         * @var SystemModules
         */
        return SystemModules::where($systemModuleSearchField, $uuidOrModelName)
            ->fromApp($app)
            ->notDeleted()
            ->firstOrFail();
    }
}
