<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Actions\ApplyDoNotContactAction;
use Kanvas\Guild\Customers\Actions\ProcessInboundConsentAction;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ConsentMatchEnum;
use Kanvas\Guild\Customers\Enums\ConsentSignalEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Factories\LeadFactory;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ChannelConfig;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpConfig;
use Kanvas\Intelligence\FollowUp\Enums\ChannelSelectionEnum;
use Kanvas\Intelligence\FollowUp\Enums\ExhaustedActionEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpModeEnum;
use Kanvas\Intelligence\FollowUp\Services\LeadOutboundChannelResolver;
use Tests\TestCase;

final class ApplyDoNotContactActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem', 'intelligence'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function test_opts_out_every_contact_and_flags_every_lead_of_the_person(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550111');
        $this->seedContact($people, ContactTypeEnum::EMAIL, 'prospect@example.com');
        $leadOne = $this->seedLead($people);
        $leadTwo = $this->seedLead($people);

        $outcome = new ApplyDoNotContactAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            lead: $leadOne,
            reason: 'STOP',
        )->execute();

        $this->assertTrue($outcome->applied);
        $this->assertTrue($outcome->isStop());
        $this->assertSame(2, $outcome->contactsOptedOut);
        $this->assertSame(2, $outcome->leadsFlagged);

        $this->assertSame(
            0,
            $people->contacts()->where('is_opt_out', 0)->count(),
            'a stop request is person-wide: no contact of any type may remain deliverable.',
        );

        $this->assertTrue((bool) $people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
        $this->assertTrue((bool) $leadOne->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
        $this->assertTrue(
            (bool) $leadTwo->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value),
            'the lead they were NOT talking on must be flagged too.',
        );
    }

    public function test_is_idempotent(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550112');
        $lead = $this->seedLead($people);

        new ApplyDoNotContactAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            lead: $lead,
        )->execute();

        $second = new ApplyDoNotContactAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            lead: $lead,
        )->execute();

        $this->assertTrue($second->alreadyOptedOut);
        $this->assertFalse($second->applied);
        $this->assertSame(0, $second->contactsOptedOut);
    }

    /**
     * The per-address guard used to normalize the destination with each contact row's OWN type, so
     * an email was compared under phone normalization — which strips a value to its last 10 digits.
     * An address carrying the same digits as an opted-out number therefore blocked itself.
     */
    public function test_an_opted_out_phone_does_not_block_an_email_with_the_same_digits(): void
    {
        $people = $this->seedPeople();
        $phone = $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550147');
        $phone->is_opt_out = 1;
        $phone->saveOrFail();
        $this->seedContact($people, ContactTypeEnum::EMAIL, 'contact8095550147@example.com');
        $lead = $this->seedLead($people);

        // The guard is what this asserts on, and it runs before delivery. Faking keeps the test off
        // the mail template, which is seeded by other suites rather than by this one.
        Notification::fake();

        $result = new SendMessageToLeadAction($lead)->execute(
            LeadCommunicationChannelEnum::EMAIL->value,
            'Your quote is ready.',
        );

        $this->assertNotSame(
            'opted_out',
            $result['classification'] ?? null,
            'An opted-out phone must not block email to an address that merely contains its digits.',
        );
    }

    /**
     * "cancel" is an FCC keyword and is honored, but it is also how someone cancels an appointment.
     * The opt-out it triggers is person-wide and the customer cannot undo it, so the tier — and the
     * review prompt it puts on the lead note — is the only way a wrong call gets found.
     */
    public function test_an_ambiguous_keyword_stops_but_is_recorded_for_review(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550151');
        $lead = $this->seedLead($people);

        $outcome = new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::WHATSAPP->value,
            body: 'cancel',
            lead: $lead,
        )->execute();

        $this->assertTrue($outcome->applied, 'an FCC keyword must still be honored.');
        $this->assertSame(ConsentMatchEnum::AMBIGUOUS_KEYWORD, $outcome->match);
        $this->assertSame(
            ConsentMatchEnum::AMBIGUOUS_KEYWORD->value,
            $lead->refresh()->get(ConsentConfigurationEnum::DO_NOT_CONTACT_MATCH->value),
        );
        $this->assertNotNull(ConsentMatchEnum::AMBIGUOUS_KEYWORD->reviewNote());
    }

    public function test_an_unambiguous_keyword_is_not_flagged_for_review(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550152');
        $lead = $this->seedLead($people);

        $outcome = new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            body: 'STOP',
            lead: $lead,
        )->execute();

        $this->assertSame(ConsentMatchEnum::EXACT, $outcome->match);
        $this->assertNull(ConsentMatchEnum::EXACT->reviewNote());
    }

    /**
     * Twilio's OptOutType means the carrier already unsubscribed the number — an act, not a reading
     * of the text — so it stays EXACT even when the body is one of the ambiguous words.
     */
    public function test_a_provider_classification_is_exact_even_for_an_ambiguous_word(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550153');
        $lead = $this->seedLead($people);

        $outcome = new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            body: 'cancel',
            lead: $lead,
            detectedSignal: ConsentSignalEnum::STOP,
        )->execute();

        $this->assertSame(ConsentMatchEnum::EXACT, $outcome->match);
    }

    /**
     * The point of the whole feature: STOP arrives on one channel and every other channel goes quiet.
     */
    public function test_stop_on_sms_blocks_whatsapp_and_email_sends(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550113');
        $this->seedContact($people, ContactTypeEnum::EMAIL, 'blocked@example.com');
        $lead = $this->seedLead($people);

        new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            body: 'STOP',
            lead: $lead,
        )->execute();

        foreach ([
            LeadCommunicationChannelEnum::SMS->value,
            LeadCommunicationChannelEnum::WHATSAPP->value,
            LeadCommunicationChannelEnum::EMAIL->value,
        ] as $channel) {
            $result = new SendMessageToLeadAction($lead->refresh())->execute($channel, 'Still there?');

            $this->assertFalse($result['success'], $channel . ' send should not succeed after a stop request.');
            $this->assertSame('opted_out', $result['classification'], $channel . ' should classify as opted_out.');
        }
    }

    /**
     * The person-level flag exists for exactly this: a connector sync creating a fresh lead after the
     * opt-out would otherwise hand the guards a lead with no flag on it.
     */
    public function test_a_lead_created_after_the_opt_out_is_still_blocked(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550114');
        $originalLead = $this->seedLead($people);

        new ApplyDoNotContactAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            lead: $originalLead,
        )->execute();

        $newLead = $this->seedLead($people);

        $result = new SendMessageToLeadAction($newLead)->execute(
            LeadCommunicationChannelEnum::SMS->value,
            'Fresh lead, same human',
        );

        $this->assertFalse($result['success']);
        $this->assertSame('opted_out', $result['classification']);
    }

    /**
     * Tier two. No exact keyword anywhere in this message, and no agent involved — the deterministic
     * phrase pass is what has to catch it.
     */
    public function test_a_phrased_stop_request_is_applied_without_the_agent(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550116');
        $lead = $this->seedLead($people);

        $outcome = new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            body: 'please take me off your list, I am not interested',
            lead: $lead,
        )->execute();

        $this->assertTrue($outcome->applied);
        $this->assertTrue($outcome->shouldHaltAgentTurn());
        $this->assertSame(ConsentMatchEnum::PHRASE, $outcome->match);
        $this->assertTrue((bool) $people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
        $this->assertSame(
            ConsentMatchEnum::PHRASE->value,
            $people->get(ConsentConfigurationEnum::DO_NOT_CONTACT_MATCH->value),
            'the flag records that this was inferred, so a human can reverse a wrong call.',
        );
    }

    /**
     * The expensive false positive: a channel preference must not silence a customer who is still
     * talking to us. Nothing is flagged, and the agent is still allowed to answer.
     */
    public function test_a_channel_preference_is_not_treated_as_an_opt_out(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550117');
        $this->seedContact($people, ContactTypeEnum::EMAIL, 'prefers-sms@example.com');
        $lead = $this->seedLead($people);

        $outcome = new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::EMAIL->value,
            body: "don't email me, text me instead",
            lead: $lead,
        )->execute();

        $this->assertFalse($outcome->applied);
        $this->assertTrue($outcome->narrowedRequest);
        $this->assertFalse($outcome->shouldHaltAgentTurn(), 'the agent must still answer them.');

        $this->assertFalse((bool) $people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
        $this->assertSame(
            2,
            $people->contacts()->where('is_opt_out', 0)->count(),
            'no contact may be opted out by a preference change.',
        );

        $result = new SendMessageToLeadAction($lead->refresh())->execute(
            LeadCommunicationChannelEnum::SMS->value,
            'Sure — texting you from now on.',
        );
        $this->assertNotSame('opted_out', $result['classification'] ?? null);
    }

    public function test_a_time_window_is_not_treated_as_an_opt_out(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550118');
        $lead = $this->seedLead($people);

        $outcome = new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            body: "don't call me before 5pm",
            lead: $lead,
        )->execute();

        $this->assertFalse($outcome->applied);
        $this->assertTrue($outcome->narrowedRequest);
        $this->assertFalse((bool) $people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
    }

    /**
     * "YES" is in Twilio's START set, so it used to register as a consent event for everyone and
     * suppress the agent turn — on the single most engaged reply a prospect can send.
     */
    public function test_yes_from_someone_who_never_opted_out_is_not_a_consent_event(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550120');
        $lead = $this->seedLead($people);

        $outcome = new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            body: 'YES',
            lead: $lead,
        )->execute();

        $this->assertNull($outcome->signal, 'a bare yes from an engaged prospect is just a reply.');
        $this->assertFalse($outcome->shouldHaltAgentTurn(), 'the agent must be allowed to answer it.');
    }

    public function test_yes_from_someone_opted_out_still_re_subscribes_them(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550121');
        $lead = $this->seedLead($people);

        new ApplyDoNotContactAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            lead: $lead,
        )->execute();

        $outcome = new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            body: 'YES',
            lead: $lead,
            contactValue: '+18095550121',
        )->execute();

        $this->assertTrue($outcome->applied);
        $this->assertFalse((bool) $people->refresh()->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
    }

    public function test_an_ordinary_message_touches_nothing(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550119');
        $lead = $this->seedLead($people);

        $outcome = new ProcessInboundConsentAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            body: 'Can I stop by the dealership tomorrow to see the Civic?',
            lead: $lead,
        )->execute();

        $this->assertNull($outcome->signal);
        $this->assertFalse($outcome->applied);
        $this->assertFalse($outcome->narrowedRequest);
        $this->assertFalse($outcome->shouldHaltAgentTurn());
        $this->assertFalse((bool) $people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
    }

    public function test_follow_up_resolver_offers_no_channels_for_a_flagged_lead(): void
    {
        $people = $this->seedPeople();
        $this->seedContact($people, ContactTypeEnum::CELLPHONE, '+18095550115');
        $this->seedContact($people, ContactTypeEnum::EMAIL, 'followup@example.com');
        $lead = $this->seedLead($people);

        $config = $this->followUpConfig();

        $this->assertNotEmpty(
            new LeadOutboundChannelResolver()->resolve($lead, $config),
            'sanity: the lead is reachable before the opt-out.',
        );

        new ApplyDoNotContactAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            lead: $lead,
        )->execute();

        $this->assertSame(
            [],
            new LeadOutboundChannelResolver()->resolve($lead->refresh(), $config),
            'follow-up must not schedule a nudge on a do-not-contact lead.',
        );
    }

    private function followUpConfig(): FollowUpConfig
    {
        return new FollowUpConfig(
            enabled: true,
            mode: FollowUpModeEnum::TIME_BASED,
            timeBased: null,
            goalBased: null,
            maxRetries: 3,
            exhaustedAction: ExhaustedActionEnum::STOP,
            agentName: null,
            promptTemplate: null,
            channels: [
                new ChannelConfig(type: LeadCommunicationChannelEnum::SMS->value, enabled: true),
                new ChannelConfig(type: LeadCommunicationChannelEnum::EMAIL->value, enabled: true),
            ],
            channelSelection: ChannelSelectionEnum::PRIORITY_ONLY,
            respectWorkHours: false,
            respectLeadOptOuts: true,
            writeSystemMessageOnStageChange: false,
        );
    }

    private function seedPeople(): People
    {
        return People::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'firstname' => 'Consent',
            'lastname' => 'Tester',
            'name' => 'Consent Tester',
        ]);
    }

    private function seedContact(People $people, ContactTypeEnum $type, string $value): Contact
    {
        return Contact::create([
            'peoples_id' => $people->getId(),
            'contacts_types_id' => $type->value,
            'value' => $value,
            'is_opt_out' => 0,
            'weight' => 0,
        ]);
    }

    private function seedLead(People $people): Lead
    {
        return new LeadFactory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->withPeopleId($people->getId())
            ->create();
    }
}
