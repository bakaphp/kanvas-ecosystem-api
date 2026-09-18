<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationApprovalModeEnum as ApprovalMode;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Tests\TestCase;

final class CorporateApplicationApprovalModeEnumTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->clearSettings();
    }

    protected function tearDown(): void
    {
        $this->clearSettings();

        parent::tearDown();
    }

    private function clearSettings(): void
    {
        foreach ([Setting::RECEIVER_ID, Setting::AUTO_APPROVE] as $setting) {
            $this->kanvasApp->del($setting->value);
            $this->kanvasApp->del($setting->legacyKey());
        }
    }

    public function testReceiverWithoutModeAndNoAppPointerIsNotAnApplication(): void
    {
        $this->assertNull(ApprovalMode::resolveFor($this->leadFor($this->receiver()), $this->kanvasApp));
    }

    public function testReceiverModeWins(): void
    {
        $receiver = $this->receiver();
        $receiver->set(ApprovalMode::RECEIVER_KEY, ApprovalMode::AUTO->value);

        $this->kanvasApp->set(Setting::RECEIVER_ID->legacyKey(), $receiver->getId());
        $this->kanvasApp->set(Setting::AUTO_APPROVE->legacyKey(), false);

        $this->assertSame(ApprovalMode::AUTO, ApprovalMode::resolveFor($this->leadFor($receiver), $this->kanvasApp));
    }

    public function testLegacyAppSettingsStillApplyToTheConfiguredReceiver(): void
    {
        $receiver = $this->receiver();
        $other = $this->receiver();

        $this->kanvasApp->set(Setting::RECEIVER_ID->legacyKey(), $receiver->getId());

        $this->assertSame(ApprovalMode::MANUAL, ApprovalMode::resolveFor($this->leadFor($receiver), $this->kanvasApp));
        $this->assertNull(ApprovalMode::resolveFor($this->leadFor($other), $this->kanvasApp));

        $this->kanvasApp->set(Setting::AUTO_APPROVE->legacyKey(), true);

        $this->assertSame(ApprovalMode::AUTO, ApprovalMode::resolveFor($this->leadFor($receiver), $this->kanvasApp));
    }

    public function testNewSettingNamesAreReadBeforeTheLegacyOnes(): void
    {
        $receiver = $this->receiver();

        $this->kanvasApp->set(Setting::RECEIVER_ID->value, $receiver->getId());
        $this->kanvasApp->set(Setting::AUTO_APPROVE->value, true);

        $this->assertSame(ApprovalMode::AUTO, ApprovalMode::resolveFor($this->leadFor($receiver), $this->kanvasApp));
    }

    private function receiver(): LeadReceiver
    {
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        return LeadReceiver::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'users_id' => $user->getId(),
            'agents_id' => 0,
            'rotations_id' => 0,
            'leads_sources_id' => 0,
            'lead_types_id' => 0,
            'name' => 'Approval mode ' . fake()->word(),
            'source_name' => 'approval-mode',
            'is_default' => 0,
        ]);
    }

    private function leadFor(LeadReceiver $receiver): Lead
    {
        return Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $receiver->companies_id)
            ->withReceiverId($receiver->getId())
            ->create();
    }
}
