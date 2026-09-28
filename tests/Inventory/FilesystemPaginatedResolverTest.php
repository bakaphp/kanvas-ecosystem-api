<?php

declare(strict_types=1);

namespace Tests\Inventory;

use App\GraphQL\Ecosystem\Queries\Filesystem\FilesystemQuery;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Mockery;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Tests\TestCase;

class FilesystemPaginatedResolverTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    /**
     * getPaginatedFileByGraphType must return a correct LengthAwarePaginator built from a
     * single query — no separate count(*) aggregate. The count(*) per parent is the N+1
     * offender when a bulk products list (sitemap crawl) resolves files for every product
     * and variant on a cold cache. Regression for Sentry KANVAS-ECOSYSTEM-5N4.
     *
     * @test
     */
    public function testPaginatedFilesResolverReturnsDataWithoutCountQuery(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'PaginatedFilesProduct-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();
        $variant->addFileFromUrl(fake()->imageUrl(), 'a.png');
        $variant->addFileFromUrl(fake()->imageUrl(), 'b.png');

        $resolver = new FilesystemQuery();
        $context = Mockery::mock(GraphQLContext::class);
        $resolveInfo = Mockery::mock(ResolveInfo::class);

        DB::connection('ecosystem')->flushQueryLog();
        DB::connection('ecosystem')->enableQueryLog();

        $paginator = $resolver->getPaginatedFileByGraphType(
            $variant,
            ['first' => 25, 'page' => 1],
            $context,
            $resolveInfo
        );

        $queries = DB::connection('ecosystem')->getQueryLog();
        DB::connection('ecosystem')->disableQueryLog();

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame(2, $paginator->total());
        $this->assertCount(2, $paginator->items());

        $countQueries = array_filter(
            $queries,
            fn (array $q): bool => str_contains($q['query'], 'count(*)') && str_contains($q['query'], 'filesystem')
        );

        $this->assertCount(
            0,
            $countQueries,
            'The paginated files resolver must not emit a count(*) aggregate query.'
        );
    }

    /**
     * Eager-loading `filesForGraphType` across a page of variants must batch every variant's
     * files into a single `entity_id IN (...)` query, and the paginated resolver must read that
     * loaded relation (zero extra queries) while still returning each variant's own files.
     * Regression for the channelVariants N+1 — Sentry KANVAS-ECOSYSTEM-65Q.
     *
     * @test
     */
    public function testFilesForGraphTypeEagerLoadBatchesAndFeedsResolver(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $variantIds = [];
        $expectedFileCount = [];

        foreach ([2, 3, 1] as $index => $fileCount) {
            $product = new CreateProductAction(
                new ProductDto(
                    app: $app,
                    company: $company,
                    user: $user,
                    name: 'BatchFilesProduct-' . $index . '-' . uniqid(),
                ),
                $user
            )->execute();

            /** @var Variants $variant */
            $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

            for ($i = 0; $i < $fileCount; $i++) {
                $variant->addFileFromUrl(fake()->imageUrl(), fake()->unique()->uuid() . '.png');
            }

            $variantIds[] = $variant->getKey();
            $expectedFileCount[$variant->getKey()] = $fileCount;
        }

        DB::connection('ecosystem')->flushQueryLog();
        DB::connection('ecosystem')->enableQueryLog();

        /** @var \Illuminate\Database\Eloquent\Collection<int, Variants> $variants */
        $variants = Variants::whereIn('id', $variantIds)
            ->with([
                'filesForGraphType' => function ($query) use ($company): void {
                    $query->where('filesystem_entities.companies_id', $company->getId());
                },
            ])
            ->get();

        $eagerQueries = array_filter(
            DB::connection('ecosystem')->getQueryLog(),
            fn (array $q): bool => str_contains($q['query'], 'from `filesystem`')
        );
        DB::connection('ecosystem')->disableQueryLog();

        $this->assertCount(
            1,
            $eagerQueries,
            'All variant files must be loaded in a single batched query.'
        );

        $resolver = new FilesystemQuery();
        $context = Mockery::mock(GraphQLContext::class);
        $resolveInfo = Mockery::mock(ResolveInfo::class);

        DB::connection('ecosystem')->flushQueryLog();
        DB::connection('ecosystem')->enableQueryLog();

        foreach ($variants as $variant) {
            $paginator = $resolver->getPaginatedFileByGraphType(
                $variant,
                ['first' => 25, 'page' => 1],
                $context,
                $resolveInfo
            );

            $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
            $this->assertSame(
                $expectedFileCount[$variant->getKey()],
                $paginator->total(),
                'Each variant must resolve its own files from the eager-loaded relation.'
            );
        }

        $resolverFileQueries = array_filter(
            DB::connection('ecosystem')->getQueryLog(),
            fn (array $q): bool => str_contains($q['query'], 'from `filesystem`')
        );
        DB::connection('ecosystem')->disableQueryLog();

        $this->assertCount(
            0,
            $resolverFileQueries,
            'The resolver must read the eager-loaded relation without re-querying filesystem.'
        );
    }

    /**
     * Parity guard: the legacy per-entity path (getFileByGraphType) and the new batched
     * eager-load path (filesForGraphType) must resolve byte-identical file rows for the same
     * variant — same columns, same values, same order. This is what makes the N+1 fix a pure
     * optimization with no behavioral change to the public API.
     *
     * @test
     */
    public function testLegacyAndBatchedPathsResolveIdenticalFiles(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'ParityFilesProduct-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $seed */
        $seed = $product->variants()->where('is_deleted', 0)->firstOrFail();
        $seed->addFileFromUrl(fake()->imageUrl(), fake()->unique()->uuid() . '.png');
        $seed->addFileFromUrl(fake()->imageUrl(), fake()->unique()->uuid() . '.png');
        $seed->addFileFromUrl(fake()->imageUrl(), fake()->unique()->uuid() . '.png');

        $resolver = new FilesystemQuery();
        $context = Mockery::mock(GraphQLContext::class);
        $resolveInfo = Mockery::mock(ResolveInfo::class);

        // Legacy path: a fresh variant with NO relation loaded falls back to getFileByGraphType.
        /** @var Variants $legacyVariant */
        $legacyVariant = Variants::whereIn('id', [$seed->getKey()])->firstOrFail();
        $this->assertFalse($legacyVariant->relationLoaded('filesForGraphType'));

        // New path: the same variant with the batched relation eager-loaded.
        /** @var Variants $batchedVariant */
        $batchedVariant = Variants::whereIn('id', [$seed->getKey()])
            ->with([
                'filesForGraphType' => function ($query) use ($company): void {
                    $query->where('filesystem_entities.companies_id', $company->getId());
                },
            ])
            ->firstOrFail();
        $this->assertTrue($batchedVariant->relationLoaded('filesForGraphType'));

        $legacy = $resolver->getPaginatedFileByGraphType($legacyVariant, ['first' => 25, 'page' => 1], $context, $resolveInfo);
        $batched = $resolver->getPaginatedFileByGraphType($batchedVariant, ['first' => 25, 'page' => 1], $context, $resolveInfo);

        $normalize = fn (LengthAwarePaginator $p): array => collect($p->items())
            ->map(fn ($file): array => [
                'uuid' => $file->uuid,
                'filesystem_uuid' => $file->filesystem_uuid,
                'field_name' => $file->field_name,
                'weight' => $file->weight,
                'name' => $file->name,
                'url' => $file->url,
                'size' => $file->size,
                'file_type' => $file->file_type,
                'type' => $file->type,
                'id' => $file->id,
            ])
            ->sortBy('uuid')
            ->values()
            ->all();

        $this->assertSame(3, $legacy->total());
        $this->assertSame($legacy->total(), $batched->total(), 'File count diverged between legacy and batched paths.');
        $this->assertEquals(
            $normalize($legacy),
            $normalize($batched),
            'Legacy and batched file rows diverged — the N+1 fix changed API output.'
        );
    }

    /**
     * Soft-deleted files must be excluded on BOTH paths — whether the deletion is on the
     * attachment (filesystem_entities.is_deleted) or the file itself (filesystem.is_deleted).
     *
     * @test
     */
    public function testSoftDeletedFilesAreExcludedOnBothPaths(): void
    {
        $this->bootInventory();
        $variant = $this->makeVariantWithFiles(3, 'softdelete');

        $systemModule = SystemModulesRepository::getByModelName(Variants::class, app(Apps::class));
        $attachments = FilesystemEntities::where('entity_id', $variant->getKey())
            ->where('system_modules_id', $systemModule->getId())
            ->where('is_deleted', 0)
            ->get();

        $this->assertCount(3, $attachments);

        // Delete #1 at the attachment level.
        $attachments[0]->update(['is_deleted' => 1]);
        // Delete #2 at the file level.
        Filesystem::where('id', $attachments[1]->filesystem_id)->update(['is_deleted' => 1]);

        $company = auth()->user()->getCurrentCompany();

        $legacy = $this->resolve($this->reloadVariant($variant->getKey()));
        $batched = $this->resolve($this->eagerVariant($variant->getKey(), $company));

        $this->assertSame(1, $legacy->total(), 'Legacy path must exclude both soft-deleted files.');
        $this->assertSame(1, $batched->total(), 'Batched path must exclude both soft-deleted files.');
        $this->assertSame(
            $attachments[2]->uuid,
            $batched->items()[0]->uuid,
            'Only the surviving file should remain.'
        );
    }

    /**
     * The company-scoped batched load must not leak a foreign company's attachment that shares
     * the same entity_id — mirroring getFileByGraphType's companies_id guard.
     *
     * @test
     */
    public function testBatchedLoadIsScopedToCompanyAndDoesNotLeakForeignFiles(): void
    {
        $this->bootInventory();
        $variant = $this->makeVariantWithFiles(2, 'tenant');

        $company = auth()->user()->getCurrentCompany();
        $systemModule = SystemModulesRepository::getByModelName(Variants::class, app(Apps::class));

        /** @var FilesystemEntities $seedAttachment */
        $seedAttachment = FilesystemEntities::where('entity_id', $variant->getKey())
            ->where('system_modules_id', $systemModule->getId())
            ->where('is_deleted', 0)
            ->firstOrFail();

        // A foreign-company attachment on the SAME entity_id / file — must be filtered out.
        FilesystemEntities::create([
            'filesystem_id' => $seedAttachment->filesystem_id,
            'companies_id' => $company->getId() + 999999,
            'system_modules_id' => $systemModule->getId(),
            'entity_id' => $variant->getKey(),
            'field_name' => 'foreign.png',
            'weight' => 0,
            'is_deleted' => 0,
        ]);

        $batched = $this->resolve($this->eagerVariant($variant->getKey(), $company));

        $this->assertSame(2, $batched->total(), 'Foreign-company attachment leaked into the batched load.');
    }

    /**
     * A variant with no files inside a batched page must resolve cleanly (loaded relation,
     * empty paginator) rather than erroring or falling back to a query.
     *
     * @test
     */
    public function testVariantWithNoFilesResolvesEmptyFromBatch(): void
    {
        $this->bootInventory();
        $withFiles = $this->makeVariantWithFiles(2, 'has');
        $withoutFiles = $this->makeVariantWithFiles(0, 'none');

        $company = auth()->user()->getCurrentCompany();

        $variants = Variants::whereIn('id', [$withFiles->getKey(), $withoutFiles->getKey()])
            ->with([
                'filesForGraphType' => function ($query) use ($company): void {
                    $query->where('filesystem_entities.companies_id', $company->getId());
                },
            ])
            ->get()
            ->keyBy(fn (Variants $v): int => $v->getKey());

        $empty = $this->resolve($variants[$withoutFiles->getKey()]);

        $this->assertTrue($variants[$withoutFiles->getKey()]->relationLoaded('filesForGraphType'));
        $this->assertSame(0, $empty->total());
        $this->assertCount(0, $empty->items());
        $this->assertSame(2, $this->resolve($variants[$withFiles->getKey()])->total());
    }

    /**
     * Pagination args (first/page) must slice the eager-loaded collection correctly.
     *
     * @test
     */
    public function testBatchedPathHonoursPaginationArgs(): void
    {
        $this->bootInventory();
        $variant = $this->makeVariantWithFiles(5, 'paginate');
        $company = auth()->user()->getCurrentCompany();

        $eager = $this->eagerVariant($variant->getKey(), $company);
        $page2 = new FilesystemQuery()->getPaginatedFileByGraphType(
            $eager,
            ['first' => 2, 'page' => 2],
            Mockery::mock(GraphQLContext::class),
            Mockery::mock(ResolveInfo::class)
        );

        $this->assertSame(5, $page2->total());
        $this->assertSame(2, $page2->currentPage());
        $this->assertCount(2, $page2->items());
    }

    private function bootInventory(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        new InventorySetup($app, $user, $user->getCurrentCompany())->run();
    }

    private function makeVariantWithFiles(int $fileCount, string $label): Variants
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $user->getCurrentCompany(),
                user: $user,
                name: 'FS-' . $label . '-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        for ($i = 0; $i < $fileCount; $i++) {
            $variant->addFileFromUrl(fake()->imageUrl(), fake()->unique()->uuid() . '.png');
        }

        return $variant;
    }

    private function reloadVariant(int $id): Variants
    {
        /** @var Variants $variant */
        $variant = Variants::whereIn('id', [$id])->firstOrFail();

        return $variant;
    }

    private function eagerVariant(int $id, mixed $company): Variants
    {
        /** @var Variants $variant */
        $variant = Variants::whereIn('id', [$id])
            ->with([
                'filesForGraphType' => function ($query) use ($company): void {
                    $query->where('filesystem_entities.companies_id', $company->getId());
                },
            ])
            ->firstOrFail();

        return $variant;
    }

    private function resolve(Variants $variant): LengthAwarePaginator
    {
        return new FilesystemQuery()->getPaginatedFileByGraphType(
            $variant,
            ['first' => 25, 'page' => 1],
            Mockery::mock(GraphQLContext::class),
            Mockery::mock(ResolveInfo::class)
        );
    }
}
