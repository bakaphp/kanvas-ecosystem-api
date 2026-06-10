<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Services;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ChannelConfig;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Kanvas\Intelligence\FollowUp\Enums\ChannelSelectionEnum;
use Kanvas\Intelligence\FollowUp\Enums\ExhaustedActionEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpModeEnum;
use Kanvas\Intelligence\FollowUp\Services\LeadOutboundChannelResolver;
use Tests\TestCase;

/**
 * Pure unit coverage on the resolver — no agent, no session, no action.
 * Verifies contact-driven channel selection in isolation.
 */
class LeadOutboundChannelResolverTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function testReturnsEmptyWhenPersonHasOnlyEmailButOnlyWhatsAppEnabled(): void
    {
        $lead = $this->makeLead();
        $this->stripAllContacts($lead);
        $lead->people->addEmail('only@example.com');

        $config = $this->makeConfig([['type' => 'whatsapp', 'enabled' => true]]);

        $candidates = new LeadOutboundChannelResolver()->resolve($lead, $config);

        $this->assertSame([], $candidates);
    }

    public function testReturnsEmailCandidateWhenPersonHasEmailAndEmailIsEnabled(): void
    {
        $lead = $this->makeLead();
        $this->stripAllContacts($lead);
        $lead->people->addEmail('lead@example.com');

        $config = $this->makeConfig([['type' => 'email', 'enabled' => true]]);

        $candidates = new LeadOutboundChannelResolver()->resolve($lead, $config);

        $this->assertCount(1, $candidates);
        $this->assertSame('email', $candidates[0]->channelType);
        $this->assertSame('lead@example.com', $candidates[0]->contact->value);
    }

    public function testPrefersPrimaryEmailOverPlainEmailOverSecondaryEmail(): void
    {
        $lead = $this->makeLead();
        $this->stripAllContacts($lead);
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::SECONDARY_EMAIL->value,
            'value' => 'secondary@example.com',
            'weight' => 0,
            'is_opt_out' => 0,
        ]);
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => 'plain@example.com',
            'weight' => 0,
            'is_opt_out' => 0,
        ]);
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::PRIMARY_EMAIL->value,
            'value' => 'primary@example.com',
            'weight' => 0,
            'is_opt_out' => 0,
        ]);

        $config = $this->makeConfig([['type' => 'email', 'enabled' => true]]);

        $candidates = new LeadOutboundChannelResolver()->resolve($lead, $config);

        $this->assertSame('primary@example.com', $candidates[0]->contact->value);
        $this->assertSame('primary_email', $candidates[0]->reason);
    }

    public function testSkipsOptedOutContact(): void
    {
        $lead = $this->makeLead();
        $this->stripAllContacts($lead);
        $lead->people->addEmail('out@example.com', isOptOut: 1);

        $config = $this->makeConfig([['type' => 'email', 'enabled' => true]]);

        $candidates = new LeadOutboundChannelResolver()->resolve($lead, $config);

        $this->assertSame([], $candidates);
    }

    public function testWhatsAppRequiresCellphoneNotJustAnyPhone(): void
    {
        $lead = $this->makeLead();
        $this->stripAllContacts($lead);
        // Home phone only — not cellphone-eligible for WhatsApp.
        $lead->people->addPhone('+1-555-100-2000');

        $config = $this->makeConfig([['type' => 'whatsapp', 'enabled' => true]]);

        $candidates = new LeadOutboundChannelResolver()->resolve($lead, $config);

        $this->assertSame([], $candidates);
    }

    public function testHigherWeightWinsWithinSameContactType(): void
    {
        $lead = $this->makeLead();
        $this->stripAllContacts($lead);
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => 'low@example.com',
            'weight' => 0,
            'is_opt_out' => 0,
        ]);
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => 'high@example.com',
            'weight' => 50,
            'is_opt_out' => 0,
        ]);

        $config = $this->makeConfig([['type' => 'email', 'enabled' => true]]);

        $candidates = new LeadOutboundChannelResolver()->resolve($lead, $config);

        $this->assertSame('high@example.com', $candidates[0]->contact->value);
    }

    public function testReturnsCandidatesInStageConfigOrder(): void
    {
        $lead = $this->makeLead();   // factory's withContacts() seeds email + phone + cellphone
        $config = $this->makeConfig([
            ['type' => 'sms', 'enabled' => true],
            ['type' => 'email', 'enabled' => true],
        ]);

        $candidates = new LeadOutboundChannelResolver()->resolve($lead, $config);

        $this->assertCount(2, $candidates);
        $this->assertSame('sms', $candidates[0]->channelType);
        $this->assertSame('email', $candidates[1]->channelType);
    }

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
    }

    private function stripAllContacts(Lead $lead): void
    {
        $lead->people->contacts()->delete();
        // Cached relation must drop so re-reads hit DB fresh.
        $lead->people->unsetRelation('contacts');
    }

    private function makeConfig(array $channels): FollowUpConfig
    {
        $channelConfigs = array_map(
            fn (array $c) => new ChannelConfig(
                type: $c['type'],
                enabled: $c['enabled'] ?? true,
                templateName: null,
            ),
            $channels,
        );

        return new FollowUpConfig(
            enabled: true,
            mode: FollowUpModeEnum::TIME_BASED,
            timeBased: null,
            goalBased: null,
            maxRetries: 5,
            exhaustedAction: ExhaustedActionEnum::STOP,
            agentName: null,
            promptTemplate: null,
            channels: $channelConfigs,
            channelSelection: ChannelSelectionEnum::STICKY_THEN_PRIORITY,
            respectWorkHours: true,
            respectLeadOptOuts: true,
            writeSystemMessageOnStageChange: true,
        );
    }
}
