<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadCustomFieldsTool;
use Tests\TestCase;

/**
 * update_lead only writes five hardcoded qualification fields, so anything a tenant configured for
 * itself was unreachable to Neuron agents — the Laravel toolset had set_lead_custom_fields, the Neuron
 * one did not.
 */
class SetLeadCustomFieldsToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testWritesCustomFieldsFromAJsonObjectString(): void
    {
        $lead = $this->makeLead();

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            custom_fields: '{"lead_score": "82", "competitor": "Salesforce"}',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(['lead_score' => '82', 'competitor' => 'Salesforce'], $result['set']);
        $this->assertEquals('82', $lead->get('lead_score'));
        $this->assertSame('Salesforce', $lead->get('competitor'));
    }

    public function testRemovesNamedFieldsAndReportsTheOnesThatWereNotSet(): void
    {
        $lead = $this->makeLead();
        $lead->set('competitor', 'Salesforce');

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            remove: 'competitor, never_set_this',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(['competitor'], $result['removed']);
        $this->assertSame(['never_set_this'], $result['not_found']);
        $this->assertNull($lead->get('competitor'));
    }

    public function testSetsAndRemovesInOneCall(): void
    {
        $lead = $this->makeLead();
        $lead->set('stale_field', 'old');

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            custom_fields: '{"lead_score": "91"}',
            remove: 'stale_field',
        );

        $this->assertSame('success', $result['status']);
        $this->assertEquals('91', $lead->get('lead_score'));
        $this->assertNull($lead->get('stale_field'));
    }

    public function testLeavesOtherFieldsAlone(): void
    {
        $lead = $this->makeLead();
        $lead->set('keep_me', 'untouched');

        $this->tool()->__invoke(lead_id: $lead->getId(), custom_fields: '{"lead_score": "50"}');

        $this->assertSame('untouched', $lead->get('keep_me'));
    }

    public function testRequiresSomethingToDo(): void
    {
        $lead = $this->makeLead();

        $result = $this->tool()->__invoke(lead_id: $lead->getId());

        $this->assertSame('error', $result['status']);
    }

    public function testDoesNotWriteToAnotherCompanysLead(): void
    {
        $foreign = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), Companies::factory()->create()->getId())
            ->create();

        $result = $this->tool()->__invoke(
            lead_id: $foreign->getId(),
            custom_fields: '{"lead_score": "99"}',
        );

        $this->assertSame('error', $result['status']);
        $this->assertNull($foreign->get('lead_score'));
    }

    private function tool(): SetLeadCustomFieldsTool
    {
        $user = auth()->user();

        return new SetLeadCustomFieldsTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    private function makeLead(): Lead
    {
        $user = auth()->user();

        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $user->getCurrentCompany()->getId())
            ->create();
    }
}
