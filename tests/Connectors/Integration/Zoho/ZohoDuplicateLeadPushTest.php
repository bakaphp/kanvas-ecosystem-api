<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoLeadDuplicateService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Users\Models\Users;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Integration\Zoho\Concerns\StubsZohoLeadModule;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Models\Record;

/**
 * A lead the duplicate-check receiver flagged reaches Zoho owned by the configured user, even through
 * the rotation branch that would otherwise set the owner, and keeps its Lead_Source.
 */
final class ZohoDuplicateLeadPushTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;
    use StubsZohoLeadModule;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testAFlaggedLeadIsCreatedWithTheDuplicateOwnerAndItsSource(): void
    {
        $lead = $this->makeRotationLead();
        $lead->set(CustomFieldEnum::ZOHO_DUPLICATE_OWNER_ID->value, '777');

        $module = $this->module();
        $module->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (array $data) => ($data['Owner'] ?? null) === '777'
                && ($data['Lead_Source'] ?? null) === $lead->receiver->name))
            ->willReturn(new Record(['id' => 'zl-dup'], $module));

        $this->action($lead, $module)->execute();
    }

    public function testAnUnflaggedLeadKeepsTheReceiverSource(): void
    {
        $lead = $this->makeRotationLead();

        $module = $this->module();
        $module->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (array $data) => ($data['Lead_Source'] ?? null) === $lead->receiver->name))
            ->willReturn(new Record(['id' => 'zl-std'], $module));

        $this->action($lead, $module)->execute();
    }

    public function testPhoneVariantsCoverTheCommonStoredFormats(): void
    {
        $this->assertSame(
            ['8095551234', '+18095551234', '(809) 555-1234', '809-555-1234'],
            ZohoLeadDuplicateService::phoneVariants('8095551234')
        );
    }

    private function makeRotationLead(): Lead
    {
        $company = $this->makeZohoCompany();
        $owner = Users::factory()->create();
        $owner->set(CustomFieldEnum::ZOHO_USER_OWNER_ID->value, '4242');

        $rotation = LeadRotation::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Rotation ' . Str::random(8),
            'hits' => 0,
            'leads_rotations_email' => '',
        ]);

        $receiver = LeadReceiver::create([
            'name' => 'Apply3 ' . Str::random(8),
            'users_id' => $owner->getId(),
            'agents_id' => $owner->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => app(Apps::class)->getId(),
            'rotations_id' => $rotation->getId(),
            'leads_sources_id' => 0,
            'lead_types_id' => 0,
            'source_name' => 'apply3',
            'is_default' => false,
        ]);

        $lead = $this->makeLead($company);
        $lead->leads_owner_id = $owner->getId();
        $lead->leads_receivers_id = $receiver->getId();
        $lead->saveOrFail();

        return $lead->fresh();
    }
}
