<?php

declare(strict_types=1);

namespace Tests\Inventory;

use App\GraphQL\Ecosystem\Queries\Filesystem\FilesystemQuery;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
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
}
