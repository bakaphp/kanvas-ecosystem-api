<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Guild\Customers\Actions\ApplyDoNotContactAction;
use Kanvas\Guild\Customers\Actions\RevokeDoNotContactAction;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SendOptOutConfirmationAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Factories\LeadFactory;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Templates\Blank;
use Tests\TestCase;

/**
 * The FCC permits exactly ONE message after a revocation, so "send at most one" is the whole job of
 * this action. Every test here asserts on the `opt_out_confirmation_sent_at` stamp rather than on
 * delivery: the stamp is written before the send precisely so a timeout that actually delivered
 * cannot leave the lead eligible for a second acknowledgement.
 */
final class SendOptOutConfirmationActionTest extends TestCase
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

        // Every assertion here is about whether the acknowledgement was ALLOWED, which is settled
        // before delivery. Faking keeps the suite off the mail template, which other suites seed.
        Notification::fake();
    }

    public function test_sends_one_acknowledgement_and_stamps_the_lead(): void
    {
        $lead = $this->seedOptedOutLead();

        $this->assertNull($lead->get(ConsentConfigurationEnum::CONFIRMATION_SENT_AT->value));

        new SendOptOutConfirmationAction($lead, LeadCommunicationChannelEnum::EMAIL->value)->execute();

        $this->assertNotNull(
            $lead->get(ConsentConfigurationEnum::CONFIRMATION_SENT_AT->value),
            'the first acknowledgement must stamp the lead.',
        );
    }

    /**
     * The one that matters: sending two after a revocation is the violation.
     */
    public function test_never_sends_a_second_acknowledgement(): void
    {
        $lead = $this->seedOptedOutLead();

        new SendOptOutConfirmationAction($lead, LeadCommunicationChannelEnum::EMAIL->value)->execute();
        $stampedAt = $lead->get(ConsentConfigurationEnum::CONFIRMATION_SENT_AT->value);

        Notification::fake();
        $second = new SendOptOutConfirmationAction($lead, LeadCommunicationChannelEnum::EMAIL->value)->execute();

        $this->assertNull($second, 'a second acknowledgement must not be sent.');
        Notification::assertNothingSent();
        $this->assertSame(
            $stampedAt,
            $lead->get(ConsentConfigurationEnum::CONFIRMATION_SENT_AT->value),
            'the original stamp must not be overwritten.',
        );
    }

    /**
     * The acknowledgement is the ONLY thing allowed through the do-not-contact guard, and it has to
     * work on a lead that is by definition already flagged — otherwise nobody is ever acknowledged.
     */
    public function test_sends_even_though_the_lead_is_flagged_do_not_contact(): void
    {
        $lead = $this->seedOptedOutLead();

        $this->assertTrue((bool) $lead->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));

        Notification::fake();
        new SendOptOutConfirmationAction($lead, LeadCommunicationChannelEnum::EMAIL->value)->execute();

        Notification::assertSentOnDemand(Blank::class);
    }

    /**
     * Twilio's own STOP filtering already replies on that channel, so ours would be the second.
     * The config defaults to true, which means the SMS lane sends nothing unless a tenant opts in.
     */
    public function test_sms_is_suppressed_by_default_because_twilio_answers_it(): void
    {
        $lead = $this->seedOptedOutLead();

        $result = new SendOptOutConfirmationAction($lead, LeadCommunicationChannelEnum::SMS->value)->execute();

        $this->assertNull($result);
        $this->assertNull($lead->get(ConsentConfigurationEnum::CONFIRMATION_SENT_AT->value));
    }

    public function test_sms_is_sent_when_the_tenant_says_twilio_does_not_reply(): void
    {
        $lead = $this->seedOptedOutLead();
        $this->company->set(TwilioConfigurationEnum::TWILIO_SENDS_OPT_OUT_REPLY->value, 0);

        try {
            new SendOptOutConfirmationAction($lead, LeadCommunicationChannelEnum::SMS->value)->execute();

            $this->assertNotNull(
                $lead->get(ConsentConfigurationEnum::CONFIRMATION_SENT_AT->value),
                'with Twilio not replying, our own acknowledgement is the permitted one.',
            );
        } finally {
            $this->company->del(TwilioConfigurationEnum::TWILIO_SENDS_OPT_OUT_REPLY->value);
        }
    }

    public function test_an_unsupported_channel_sends_nothing(): void
    {
        $lead = $this->seedOptedOutLead();

        $result = new SendOptOutConfirmationAction($lead, LeadCommunicationChannelEnum::VOICE->value)->execute();

        $this->assertNull($result);
        $this->assertNull($lead->get(ConsentConfigurationEnum::CONFIRMATION_SENT_AT->value));
    }

    /**
     * The allowance is per revocation, not per lifetime. The stamp used to survive a re-subscribe,
     * so a customer who opted out, came back, and opted out again was silently never acknowledged
     * the second time — for the rest of that lead's life.
     */
    public function test_a_re_subscribe_restores_the_acknowledgement_allowance(): void
    {
        $lead = $this->seedOptedOutLead();

        new SendOptOutConfirmationAction($lead, LeadCommunicationChannelEnum::EMAIL->value)->execute();
        $this->assertNotNull($lead->get(ConsentConfigurationEnum::CONFIRMATION_SENT_AT->value));

        new RevokeDoNotContactAction(
            people: $lead->people,
            sourceChannel: LeadCommunicationChannelEnum::EMAIL->value,
            lead: $lead,
        )->execute();

        $this->assertNull(
            $lead->refresh()->get(ConsentConfigurationEnum::CONFIRMATION_SENT_AT->value),
            'a re-subscribe must clear the stamp.',
        );

        new ApplyDoNotContactAction(
            people: $lead->people,
            sourceChannel: LeadCommunicationChannelEnum::EMAIL->value,
            lead: $lead,
        )->execute();

        Notification::fake();
        new SendOptOutConfirmationAction($lead->refresh(), LeadCommunicationChannelEnum::EMAIL->value)->execute();

        Notification::assertSentOnDemand(Blank::class);
    }

    private function seedOptedOutLead(): Lead
    {
        $people = People::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'firstname' => 'Optout',
            'lastname' => 'Tester',
            'name' => 'Optout Tester',
        ]);

        foreach ([
            [ContactTypeEnum::EMAIL, 'optout' . uniqid('', true) . '@example.com'],
            [ContactTypeEnum::CELLPHONE, '+1809' . fake()->numerify('#######')],
        ] as [$type, $value]) {
            Contact::create([
                'peoples_id' => $people->getId(),
                'contacts_types_id' => $type->value,
                'value' => $value,
                'is_opt_out' => 0,
                'weight' => 0,
            ]);
        }

        $lead = new LeadFactory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->withPeopleId($people->getId())
            ->create();

        new ApplyDoNotContactAction(
            people: $people,
            sourceChannel: LeadCommunicationChannelEnum::SMS->value,
            lead: $lead,
        )->execute();

        return $lead->refresh();
    }
}
