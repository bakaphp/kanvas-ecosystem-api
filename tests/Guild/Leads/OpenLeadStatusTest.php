<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Tests\TestCase;

final class OpenLeadStatusTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testStatusRowFlaggedOpenCountsAsOpen(): void
    {
        $lead = $this->leadWithStatus($this->statusId(isOpen: true));

        $this->assertTrue($lead->hasOpenLeadStatus());
        $this->assertTrue($this->openScopeMatches($lead));
        $this->assertFalse($this->closedScopeMatches($lead));
    }

    public function testStatusRowNotFlaggedOpenIsClosed(): void
    {
        $lead = $this->leadWithStatus($this->statusId(isOpen: false));

        $this->assertFalse($lead->hasOpenLeadStatus());
        $this->assertFalse($this->openScopeMatches($lead));
        $this->assertTrue($this->closedScopeMatches($lead));
    }

    public function testLeadPointingAtAMissingStatusRowIsClosed(): void
    {
        $lead = $this->leadWithStatus(99999999);

        $this->assertFalse($lead->hasOpenLeadStatus());
        $this->assertFalse($this->openScopeMatches($lead));
        $this->assertTrue($this->closedScopeMatches($lead));
    }

    public function testLegacyCompanySettingOpensAnUnflaggedStatus(): void
    {
        $closedStatusId = $this->statusId(isOpen: false);
        $lead = $this->leadWithStatus($closedStatusId);

        $this->withLegacyOpenIds([$closedStatusId], function () use ($lead): void {
            $this->assertTrue($lead->hasOpenLeadStatus());
            $this->assertTrue($this->openScopeMatches($lead));
            $this->assertFalse($this->closedScopeMatches($lead));
        });
    }

    public function testLegacyOpenIdsDoNotWidenAQueryChainedWithOtherWheres(): void
    {
        $legacyOpen = $this->leadWithStatus($this->statusId(isOpen: false));
        $other = $this->leadWithStatus($this->statusId(isOpen: false));

        $this->withLegacyOpenIds([$legacyOpen->leads_status_id], function () use ($legacyOpen, $other): void {
            $company = $this->company();

            $this->assertFalse(
                Lead::query()->where('id', $other->getId())->hasOpenLeadStatus($company)->exists()
            );
            $this->assertSame(
                [$legacyOpen->getId()],
                Lead::query()
                    ->whereIn('id', [$legacyOpen->getId(), $other->getId()])
                    ->hasOpenLeadStatus($company)
                    ->pluck('id')
                    ->all()
            );
        });
    }

    public function testDealReadsTheSameFlag(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $created = new CreateDealTool($app, $company, $user)->__invoke(title: 'Flag deal ' . uniqid());
        $deal = Deal::getByIdFromCompanyApp((int) $created['deal_id'], $company, $app);

        $deal->status_id = $this->statusId(isOpen: true);
        $deal->saveOrFail();
        $this->assertTrue($deal->hasOpenStatus());
        $this->assertTrue($this->dealOpenScopeMatches($deal));
        $this->assertFalse($this->dealClosedScopeMatches($deal));

        $deal->status_id = $this->statusId(isOpen: false);
        $deal->saveOrFail();
        $this->assertFalse($deal->hasOpenStatus());
        $this->assertFalse($this->dealOpenScopeMatches($deal));
        $this->assertTrue($this->dealClosedScopeMatches($deal));
    }

    /**
     * The setting lives in Redis, which DatabaseTransactions does not roll back, so it is
     * deleted even when the assertions fail.
     */
    private function withLegacyOpenIds(array $ids, callable $assertions): void
    {
        $company = $this->company();
        $company->set(ConfigurationEnum::OPEN_LEADS_STATUS_IDS->value, $ids);

        try {
            $assertions();
        } finally {
            $company->del(ConfigurationEnum::OPEN_LEADS_STATUS_IDS->value);
        }
    }

    private function statusId(bool $isOpen): int
    {
        return LeadStatus::create([
            'name' => 'OpenFlag-' . uniqid(),
            'apps_id' => 0,
            'companies_id' => 0,
            'is_default' => 0,
            'is_open' => $isOpen,
        ])->getId();
    }

    private function leadWithStatus(int $statusId): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company()->getId())
            ->create(['leads_status_id' => $statusId]);
    }

    private function company(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }

    private function openScopeMatches(Lead $lead): bool
    {
        return Lead::query()->where('id', $lead->getId())->hasOpenLeadStatus($this->company())->exists();
    }

    private function closedScopeMatches(Lead $lead): bool
    {
        return Lead::query()->where('id', $lead->getId())->hasClosedLeadStatus($this->company())->exists();
    }

    private function dealOpenScopeMatches(Deal $deal): bool
    {
        return Deal::query()->where('id', $deal->getId())->hasOpenDealStatus($this->company())->exists();
    }

    private function dealClosedScopeMatches(Deal $deal): bool
    {
        return Deal::query()->where('id', $deal->getId())->hasClosedDealStatus($this->company())->exists();
    }
}
