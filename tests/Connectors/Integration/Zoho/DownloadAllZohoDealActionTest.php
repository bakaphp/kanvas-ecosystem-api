<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Actions\DownloadAllZohoDealAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Deals\Models\Deal;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\TestCase;

/**
 * Only the page fetch is stubbed; each record still goes through the real PullZohoDealAction, so
 * this is the same path the command walks minus the HTTP.
 */
final class DownloadAllZohoDealActionTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testWalksPagesUntilZohoRunsOutAndSkipsRecordsWithoutAnId(): void
    {
        $company = $this->makeZohoCompany();
        $pages = [
            1 => [
                ['id' => 'zd-p1', 'Deal_Name' => 'Page one deal'],
                ['Deal_Name' => 'No id, never created'],
            ],
            2 => [
                ['id' => 'zd-p2', 'Deal_Name' => 'Page two deal'],
            ],
        ];

        $download = new class (
            app(Apps::class),
            $company,
            auth()->user(),
            $pages
        ) extends DownloadAllZohoDealAction {
            public array $pagesAsked = [];

            public function __construct(
                AppInterface $app,
                CompanyInterface $company,
                UserInterface $user,
                private array $pages
            ) {
                parent::__construct($app, $company, $user);
            }

            protected function fetchPage(int $page, int $perPage): array
            {
                $this->pagesAsked[] = $page;

                return $this->pages[$page] ?? [];
            }
        };

        $deals = iterator_to_array($download->execute(totalPages: 50, dealsPerPage: 200), false);

        $this->assertCount(2, $deals);
        $this->assertSame(2, $download->getTotalDealsProcessed());
        $this->assertSame([1, 2, 3], $download->pagesAsked, 'stops at the first empty page instead of walking all 50');
        $this->assertSame(
            ['zd-p1', 'zd-p2'],
            array_map(fn (Deal $deal) => $deal->get(CustomFieldEnum::ZOHO_DEAL_ID->value), $deals)
        );
        $this->assertSame(2, Deal::fromCompany($company)->notDeleted()->count());
    }
}
