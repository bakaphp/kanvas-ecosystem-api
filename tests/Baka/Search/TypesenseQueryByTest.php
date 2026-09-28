<?php

declare(strict_types=1);

namespace Tests\Baka\Search;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Templates\Models\Templates;
use Kanvas\Users\Models\Users;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression for KANVAS-ECOSYSTEM-626.
 *
 * Typesense rejects any query without a `query_by` ("No search fields specified for the query").
 * Unlike Algolia/Meilisearch, that parameter is not optional. In this codebase it is supplied
 * per-model inside each `search()` override, behind `isTypesense()`. Every DynamicSearchableTrait
 * model that can run on Typesense must set it or its `@search` query 400s the moment the app (or
 * that module) is switched to Typesense.
 *
 * Engine resolution is forced in-memory by mocking the app's `get()` — the app's `search_engine`
 * setting lives in shared Redis, so persisting it (even in a DB transaction) would leak Typesense
 * into sibling tests running in parallel and blow them up with "api_key is not defined".
 */
final class TypesenseQueryByTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string}>
     */
    public static function typesenseModelProvider(): array
    {
        return [
            'users' => [Users::class, 'firstname,lastname,displayname,email'],
            'templates' => [Templates::class, 'name,subject,title'],
            'agent' => [Agent::class, 'name,slug,description'],
            'order' => [Order::class, 'order_number_text,user_email,user_phone,tracking_client_id,customer_name,products_text,metadata_text'],
            'variants' => [Variants::class, 'name,sku,ean,barcode,description,short_description,tags'],
            'message' => [Message::class, 'message_text'],
            'role' => [Role::class, 'name,title'],
        ];
    }

    #[DataProvider('typesenseModelProvider')]
    public function testSearchSetsQueryByOnTypesense(string $modelClass, string $expectedQueryBy): void
    {
        $this->forceTypesense();

        try {
            $builder = $modelClass::search('lookup');

            $this->assertSame(
                $expectedQueryBy,
                $builder->options['query_by'] ?? null,
                $modelClass . '::search() must set query_by on Typesense or it 400s with "No search fields specified for the query".'
            );
        } finally {
            app()->forgetInstance(Apps::class);
        }
    }

    /**
     * Roles are scoped by the Bouncer `scope` column (app_{id}_company_0), not apps_id/companies_id.
     * `@search` bypasses the `@paginate(builder:)` scoping, so `Role::search()` is the only place that
     * can stop roles leaking across apps.
     */
    public function testRoleSearchIsScopedToCurrentAppScope(): void
    {
        $expectedScope = RolesEnums::getScope(app(Apps::class), null);

        $scopeWhere = collect(Role::search('admin')->wheres)->firstWhere('field', 'scope');

        $this->assertNotNull($scopeWhere, 'Role::search() must filter by Bouncer scope or it leaks roles across apps.');
        $this->assertSame($expectedScope, $scopeWhere['value']);
    }

    /**
     * An app with no engine configured + global SCOUT_DRIVER=null must resolve to a string
     * ('null' → NullEngine), not TypeError the ": string" return of resolvedEngineName(). search()
     * calls isTypesense(), so a null return would crash search on every unconfigured app.
     */
    public function testSearchDoesNotBreakWhenNoEngineConfigured(): void
    {
        $realApp = app(Apps::class);
        $app = Mockery::mock($realApp)->makePartial();
        $app->shouldReceive('get')->andReturnUsing(
            fn (string $name, mixed $default = null): mixed => str_ends_with($name, 'search_engine')
                ? null
                : $realApp->get($name, $default)
        );
        app()->instance(Apps::class, $app);
        config(['scout.driver' => null]);

        try {
            $builder = Users::search('lookup');

            $this->assertArrayNotHasKey('query_by', $builder->options);
            $this->assertFalse($builder->model->isTypesense());
        } finally {
            app()->forgetInstance(Apps::class);
        }
    }

    /**
     * Rebind app(Apps::class) to a partial mock whose `get()` reports the Typesense engine for any
     * *_search_engine key and passes everything else through to the real app. Process-local, so it
     * never touches shared Redis/DB.
     */
    private function forceTypesense(): void
    {
        $realApp = app(Apps::class);
        $app = Mockery::mock($realApp)->makePartial();
        $app->shouldReceive('get')->andReturnUsing(
            fn (string $name, mixed $default = null): mixed => str_ends_with($name, 'search_engine')
                ? 'typesense'
                : $realApp->get($name, $default)
        );

        app()->instance(Apps::class, $app);
    }
}
