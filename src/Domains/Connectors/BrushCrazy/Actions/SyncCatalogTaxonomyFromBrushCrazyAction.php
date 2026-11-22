<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\Money;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Inventory\Categories\Models\Categories;

/**
 * BrushCrazy's product taxonomy, copied into each studio company as Inventory categories. A product
 * has one `category` (accounting and pricing: GL account, formula tiers, `transferable`) and an
 * independent `group` (what the shop browses by), so both become categories on two branches:
 * category type → category, and a `bc-groups` root → group. Groups are not product types: those
 * mean "kind of product with attributes", which a group is not.
 *
 * Keyed by slug (`bc-category-type-{id}`, `bc-category-{id}`, `bc-group-{id}`), which the unique
 * (company, slug, app) index makes a reliable external id. Saved with events on: the category tree
 * builds its materialized path in a model hook.
 */
class SyncCatalogTaxonomyFromBrushCrazyAction
{
    public const string CATEGORY_MORPH = 'category';

    public const string CATEGORY_TYPE_MORPH = 'category-type';

    public const string GROUP_MORPH = 'group';

    public const string FORMULA_MORPH = 'formula';

    public const array MORPHS = [
        self::CATEGORY_MORPH,
        self::CATEGORY_TYPE_MORPH,
        self::GROUP_MORPH,
        self::FORMULA_MORPH,
    ];

    public const string GROUPS_ROOT_SLUG = 'bc-groups';

    /** @var array<int, int>|null BrushCrazy category id => Kanvas category id */
    protected ?array $categories = null;

    /** @var array<int, int>|null BrushCrazy group id => Kanvas category id */
    protected ?array $groups = null;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        protected ?Client $client = null,
    ) {
    }

    /**
     * @return array{category_types: int, categories: int, groups: int}
     */
    public function execute(): array
    {
        return $this->syncRows(
            $this->client()->table('category_types')->get(),
            $this->client()->table('categories')->get(),
            $this->client()->table('groups')->get(),
            $this->accounts(),
            $this->formulas(),
        );
    }

    /**
     * Soft-deleted rows are copied too, flagged deleted: an old product still points at them.
     *
     * @param  iterable<object>  $types
     * @param  iterable<object>  $categories
     * @param  iterable<object>  $groups
     * @param  array<int, string>  $accountNames  account id => name
     * @param  array<int, array<int, array<string, mixed>>>  $formulasByCategory  category id => tiers
     * @return array{category_types: int, categories: int, groups: int}
     */
    public function syncRows(
        iterable $types,
        iterable $categories,
        iterable $groups,
        array $accountNames = [],
        array $formulasByCategory = []
    ): array {
        $typeIds = [];

        foreach ($types as $type) {
            $typeIds[(int) $type->id] = $this->upsertCategory(
                self::categoryTypeSlug((int) $type->id),
                (string) $type->name,
                null,
                self::isDeleted($type)
            )->getId();
        }

        $this->categories ??= [];

        foreach ($categories as $category) {
            $parentId = $category->type_id !== null
                ? ($typeIds[(int) $category->type_id] ?? $this->idOf(self::categoryTypeSlug((int) $category->type_id)))
                : null;

            $kanvasCategory = $this->upsertCategory(
                self::categorySlug((int) $category->id),
                (string) $category->name,
                $parentId,
                self::isDeleted($category)
            );

            $this->setSettings($kanvasCategory, [
                'account' => $category->account_id !== null ? ($accountNames[(int) $category->account_id] ?? null) : null,
                'transferable' => (bool) ($category->transferable ?? false),
                'formulas' => $formulasByCategory[(int) $category->id] ?? [],
            ]);

            $this->categories[(int) $category->id] = $kanvasCategory->getId();
        }

        $this->groups ??= [];
        $groupsRoot = null;

        foreach ($groups as $group) {
            $groupsRoot ??= $this->upsertCategory(self::GROUPS_ROOT_SLUG, 'Groups', null, false);

            $this->groups[(int) $group->id] = $this->upsertCategory(
                self::groupSlug((int) $group->id),
                (string) $group->name,
                $groupsRoot->getId(),
                self::isDeleted($group)
            )->getId();
        }

        return [
            'category_types' => count($typeIds),
            'categories' => count($this->categories),
            'groups' => count($this->groups),
        ];
    }

    /**
     * A pointer re-reads its row. A formula pointer re-syncs the category that owns it, so the
     * category's tiers stay whole.
     */
    public function fromPointer(string $morph, int $entityId): string
    {
        if ($morph === self::FORMULA_MORPH) {
            $categoryId = $this->client()->table('formulas')->where('id', $entityId)->value('category_id');

            if ($categoryId === null) {
                return SyncSaleFromBrushCrazyAction::MISSING;
            }

            $morph = self::CATEGORY_MORPH;
            $entityId = (int) $categoryId;
        }

        $table = match ($morph) {
            self::CATEGORY_TYPE_MORPH => 'category_types',
            self::CATEGORY_MORPH => 'categories',
            default => 'groups',
        };

        $row = $this->client()->table($table)->where('id', $entityId)->first();

        if ($row === null) {
            return SyncSaleFromBrushCrazyAction::MISSING;
        }

        match ($morph) {
            self::CATEGORY_TYPE_MORPH => $this->syncRows([$row], [], []),
            self::CATEGORY_MORPH => $this->syncRows(
                $row->type_id !== null
                    ? $this->client()->table('category_types')->where('id', $row->type_id)->get()
                    : [],
                [$row],
                [],
                $this->accounts(),
                $this->formulas((int) $row->id),
            ),
            default => $this->syncRows([], [], [$row]),
        };

        return SyncSaleFromBrushCrazyAction::SYNCED;
    }

    public function categoryIdFor(?int $bcCategoryId): ?int
    {
        if ($bcCategoryId === null) {
            return null;
        }

        $this->categories ??= $this->loadIds('bc-category-');

        return $this->categories[$bcCategoryId] ?? null;
    }

    public function groupCategoryIdFor(?int $bcGroupId): ?int
    {
        if ($bcGroupId === null) {
            return null;
        }

        $this->groups ??= $this->loadIds('bc-group-');

        return $this->groups[$bcGroupId] ?? null;
    }

    public static function categorySlug(int $bcCategoryId): string
    {
        return 'bc-category-' . $bcCategoryId;
    }

    public static function categoryTypeSlug(int $bcCategoryTypeId): string
    {
        return 'bc-category-type-' . $bcCategoryTypeId;
    }

    public static function groupSlug(int $bcGroupId): string
    {
        return 'bc-group-' . $bcGroupId;
    }

    protected function upsertCategory(string $slug, string $name, ?int $parentId, bool $isDeleted): Categories
    {
        $category = $this->find($slug) ?? new Categories();

        $category->forceFill([
            'name' => $name,
            'parent_id' => $parentId,
            'is_published' => ! $isDeleted,
            'is_deleted' => $isDeleted,
        ]);

        if (! $category->exists) {
            $category->forceFill([
                'slug' => $slug,
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->context->companyId(),
                'users_id' => $this->user->getId(),
                'position' => 0,
                'weight' => 0,
            ]);
        }

        if (! $category->exists || $category->isDirty()) {
            $category->save();
        }

        return $category;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function setSettings(Categories $category, array $settings): void
    {
        $field = CustomFieldEnum::BRUSHCRAZY_CATEGORY_SETTINGS->value;

        if ($category->get($field) == $settings) {
            return;
        }

        if (method_exists($category, 'disableWorkflows')) {
            $category->disableWorkflows();
        }

        $category->set($field, $settings);
    }

    protected function find(string $slug): ?Categories
    {
        return Categories::withTrashed()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->context->companyId())
            ->where('slug', $slug)
            ->first();
    }

    protected function idOf(string $slug): ?int
    {
        return $this->find($slug)?->getId();
    }

    /**
     * @return array<int, int> BrushCrazy id => Kanvas id
     */
    protected function loadIds(string $prefix): array
    {
        $ids = [];

        $rows = Categories::withTrashed()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->context->companyId())
            ->where('slug', 'like', $prefix . '%')
            ->get(['id', 'slug']);

        foreach ($rows as $row) {
            $suffix = substr((string) $row->slug, strlen($prefix));

            // `bc-category-` also prefixes `bc-category-type-`; only a bare number is this kind.
            if (ctype_digit($suffix)) {
                $ids[(int) $suffix] = (int) $row->getId();
            }
        }

        return $ids;
    }

    /**
     * @return array<int, string>
     */
    protected function accounts(): array
    {
        return $this->client()->table('accounts')->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }

    /**
     * @return array<int, array<int, array<string, mixed>>> category id => tiers, cheapest first
     */
    protected function formulas(?int $bcCategoryId = null): array
    {
        $query = $this->client()->table('formulas')->whereNotNull('category_id')->orderBy('id');

        if ($bcCategoryId !== null) {
            $query->where('category_id', $bcCategoryId);
        }

        $tiers = [];

        foreach ($query->get() as $formula) {
            $tiers[(int) $formula->category_id][] = [
                'from_cents' => Money::fromRaw($formula->start)->cents,
                'to_cents' => Money::fromRaw($formula->end)->cents,
                'formula' => (string) $formula->formula,
                'precision' => (float) $formula->precision,
            ];
        }

        foreach ($tiers as &$categoryTiers) {
            usort($categoryTiers, fn (array $a, array $b) => $a['from_cents'] <=> $b['from_cents']);
        }

        return $tiers;
    }

    protected static function isDeleted(object $row): bool
    {
        return ($row->deleted_at ?? null) !== null;
    }

    protected function client(): Client
    {
        return $this->client ??= new Client($this->app);
    }
}
