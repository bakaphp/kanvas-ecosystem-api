<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\PullZohoDealAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Deals\Models\Deal;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\TestCase;

final class PullZohoDealActionTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testCreatesTheDealLinkedToItsLeadAndKeepsTheZohoIds(): void
    {
        $company = $this->makeZohoCompany();
        $lead = $this->makeLead($company);
        $lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-9');

        $info = [
            'id' => 'zd-9',
            'Deal_Name' => 'Acme funding',
            'Description' => 'From Zoho',
            'ZCRM_Lead_ID' => 'zl-9',
            'Business_Name' => 'Acme LLC',
            'Amount_Requested' => 50000,
        ];

        $deal = $this->action($company, 'zd-9', $info)->execute();

        $this->assertInstanceOf(Deal::class, $deal);
        $this->assertTrue($deal->wasRecentlyCreated);
        $this->assertSame('Acme funding', $deal->title);
        $this->assertSame('From Zoho', $deal->description);
        $this->assertSame($lead->getId(), (int) $deal->leads_id);
        $this->assertSame((int) $lead->people_id, (int) $deal->people_id);
        $this->assertSame('zd-9', $deal->get(CustomFieldEnum::ZOHO_DEAL_ID->value));
        $this->assertSame('Acme funding', $deal->get(CustomFieldEnum::ZOHO_DEAL_RAW->value)['Deal_Name']);
        $this->assertSame('Acme LLC', $deal->get('business_legal_name'));
        $this->assertSame(50000, $deal->get('funding_amount_requested'));
    }

    public function testACompanyMapInTheLeadShapeIsReadOnThePull(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::ZOHO_DEAL_FIELDS_MAP->value, [
            'zip' => ['name' => 'Zip_Code', 'type' => 'string'],
            'amount_requested' => ['name' => 'Amount_Requested', 'type' => 'currency'],
        ]);

        $deal = $this->action($company, 'zd-12', [
            'id' => 'zd-12',
            'Deal_Name' => 'Mapped',
            'Zip_Code' => '33033',
            'Amount_Requested' => 25000,
        ])->execute();

        $this->assertSame('33033', (string) $deal->get('zip'));
        $this->assertSame(25000, $deal->get('amount_requested'));
    }

    public function testASecondPullUpdatesTheSameDealInsteadOfAddingOne(): void
    {
        $company = $this->makeZohoCompany();
        $info = ['id' => 'zd-10', 'Deal_Name' => 'First title'];

        $first = $this->action($company, 'zd-10', $info)->execute();
        $second = $this->action($company, 'zd-10', ['Deal_Name' => 'Renamed in Zoho'] + $info)->execute();

        $this->assertSame($first->getId(), $second->getId());
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame('Renamed in Zoho', $second->fresh()->title);
        $this->assertSame(
            1,
            Deal::getByCustomFieldBuilderTransactionSafe(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-10', $company)->count()
        );
    }

    public function testReturnsNullWhenZohoHasNoSuchDeal(): void
    {
        $company = $this->makeZohoCompany();

        $this->assertNull($this->action($company, 'zd-missing', [])->execute());
        $this->assertNull(Deal::getByCustomField(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-missing', $company));
    }

    public function testFetchesTheDealFromZohoWhenNoPayloadIsHandedIn(): void
    {
        $company = $this->makeZohoCompany();

        $deal = $this->action($company, 'zd-11', ['id' => 'zd-11', 'Deal_Name' => 'Fetched'])->execute();

        $this->assertSame('Fetched', $deal->title);
    }

    private function action(Companies $company, string $zohoDealId, array $fetched): PullZohoDealAction
    {
        return new class (
            app(Apps::class),
            $company,
            auth()->user(),
            $zohoDealId,
            $fetched
        ) extends PullZohoDealAction {
            public function __construct(
                AppInterface $app,
                CompanyInterface $company,
                UserInterface $user,
                string $zohoDealId,
                private array $fetched
            ) {
                parent::__construct($app, $company, $user, $zohoDealId);
            }

            protected function fetchDeal(): array
            {
                return $this->fetched;
            }

            protected function pullAttachments(Deal $deal): void
            {
            }
        };
    }
}
