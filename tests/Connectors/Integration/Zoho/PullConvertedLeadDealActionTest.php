<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\PullConvertedLeadDealAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\TestCase;

final class PullConvertedLeadDealActionTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    private const CONVERTED_DETAIL = [
        'deal' => 'zd-77',
        'contact' => 'zc-77',
        'account' => 'za-77',
        'convert_date' => '2026-11-09T08:57:52-05:00',
    ];

    private Companies $company;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeZohoCompany();
        $this->lead = $this->makeLead($this->company);
        $this->lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-77');
    }

    public function testPullsTheDealAConvertedLeadBecameAndMarksTheLead(): void
    {
        $zoho = $this->zohoService();
        $zoho->expects($this->once())
            ->method('getConvertedLeadDetail')
            ->with('zl-77')
            ->willReturn(self::CONVERTED_DETAIL);
        $action = $this->action($zoho);

        $deal = $action->execute();

        $this->assertInstanceOf(Deal::class, $deal);
        $this->assertSame(['zd-77'], $action->pulled);
        $this->assertSame($this->lead->getId(), (int) $deal->leads_id);
        $this->assertSame(
            self::CONVERTED_DETAIL,
            $this->lead->fresh()->get(CustomFieldEnum::ZOHO_LEAD_CONVERTED_DETAIL->value)
        );
    }

    public function testDoesNotAskZohoAgainOnceTheLeadIsMarked(): void
    {
        $this->lead->set(CustomFieldEnum::ZOHO_LEAD_CONVERTED_DETAIL->value, self::CONVERTED_DETAIL);
        $zoho = $this->zohoService();
        $zoho->expects($this->never())->method('getConvertedLeadDetail');
        $action = $this->action($zoho);

        $deal = $action->execute();

        $this->assertInstanceOf(Deal::class, $deal);
        $this->assertSame(['zd-77'], $action->pulled);
    }

    public function testLeavesALeadZohoStillHoldsAsALeadAlone(): void
    {
        $zoho = $this->zohoService();
        $zoho->expects($this->once())->method('getConvertedLeadDetail')->willReturn(null);
        $action = $this->action($zoho);

        $this->assertNull($action->execute());
        $this->assertSame([], $action->pulled);
        $this->assertNull($this->lead->fresh()->get(CustomFieldEnum::ZOHO_LEAD_CONVERTED_DETAIL->value));
    }

    public function testALeadZohoDoesNotHaveIsNeverLookedUp(): void
    {
        $lead = $this->makeLead($this->company);
        $zoho = $this->zohoService();
        $zoho->expects($this->never())->method('getConvertedLeadDetail');

        $this->assertNull($this->action($zoho, $lead)->execute());
    }

    public function testAConvertedLeadWithoutADealPullsNothing(): void
    {
        $zoho = $this->zohoService();
        $zoho->expects($this->once())
            ->method('getConvertedLeadDetail')
            ->willReturn(['contact' => 'zc-77', 'account' => 'za-77']);
        $action = $this->action($zoho);

        $this->assertNull($action->execute());
        $this->assertSame([], $action->pulled);
    }

    private function zohoService(): MockObject&ZohoService
    {
        return $this->createMock(ZohoService::class);
    }

    /**
     * The Zoho deal pull is stubbed: it is covered by PullZohoDealActionTest, including the lead handed in.
     */
    private function action(ZohoService $zoho, ?Lead $lead = null): PullConvertedLeadDealAction
    {
        $makeDeal = fn (Lead $lead): Deal => $this->makeDeal($this->company, lead: $lead);

        return new class ($lead ?? $this->lead, $zoho, $makeDeal) extends PullConvertedLeadDealAction {
            /** @var list<string> */
            public array $pulled = [];

            public function __construct(Lead $lead, ZohoService $zohoService, private Closure $makeDeal)
            {
                parent::__construct($lead, $zohoService);
            }

            protected function pullDeal(string $zohoDealId): ?Deal
            {
                $this->pulled[] = $zohoDealId;

                $deal = ($this->makeDeal)($this->lead);
                $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, $zohoDealId);

                return $deal;
            }
        };
    }
}
