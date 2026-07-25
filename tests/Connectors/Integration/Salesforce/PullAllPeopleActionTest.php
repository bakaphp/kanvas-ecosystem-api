<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Actions\PullAllPeopleAction;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class PullAllPeopleActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testAccumulatesRecordsAcrossAllPages(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);
        $this->fakeSalesforceOAuth();

        Http::fake([
            // `?*` (not a bare `*`) so this doesn't also swallow the page-2 URL below — see the
            // identical comment in PullAllOrganizationsActionTest for why the bare wildcard causes
            // an infinite pagination loop.
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query?*' => Http::response([
                'totalSize' => 2,
                'done' => false,
                'nextRecordsUrl' => '/services/data/v60.0/query/01gYY-2000',
                'records' => [['Id' => '003xx0000000001AAA', 'FirstName' => 'John', 'LastName' => 'Appleseed']],
            ], 200),
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query/01gYY-2000' => Http::response([
                'totalSize' => 2,
                'done' => true,
                'records' => [['Id' => '003xx0000000002AAA', 'FirstName' => 'Jane', 'LastName' => 'Doe']],
            ], 200),
        ]);

        $records = new PullAllPeopleAction($app, $company)->execute();

        $this->assertCount(2, $records);
        $this->assertSame('003xx0000000001AAA', $records[0]['Id']);
        $this->assertSame('003xx0000000002AAA', $records[1]['Id']);

        Http::assertSent(function ($request) {
            // `url()` includes the `?q=...` query string Http::get() appends — an exact-match
            // comparison against the bare path never matched, it just never got reached before
            // the pagination bug this test exists to cover was fixed.
            // The page-2 request shares the same URL prefix but has no `q` param at all — guard
            // with `?? ''` or PHP throws "Undefined array key" on that request.
            return str_starts_with($request->url(), self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query')
                && str_contains((string) ($request['q'] ?? ''), 'FROM Contact');
        });
    }

    public function testReturnsEmptyArrayWhenThereAreNoRecords(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);
        $this->fakeSalesforceOAuth();

        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query*' => Http::response([
                'totalSize' => 0,
                'done' => true,
                'records' => [],
            ], 200),
        ]);

        $records = new PullAllPeopleAction($app, $company)->execute();

        $this->assertSame([], $records);
    }
}
