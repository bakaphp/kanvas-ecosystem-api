<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum as SalesAssistConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

// Turns an app setting on, which every paratest process shares through Redis.
#[Group('serial')]
final class ContactObserverSocialChannelsTest extends TestCase
{
    private const int ACTIVE_STATUS_ID = 1;
    private const int LOST_STATUS_ID = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $company = auth()->user()->getCurrentCompany();
        $company->del(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value);
        app(Apps::class)->set(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value, true);
        $company->set(SalesAssistConfigurationEnum::AGENT_NAME->value, 'contact-observer-agent');

        $this->createAgent('contact-observer-agent');
    }

    protected function tearDown(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $company->del(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value);
        $company->del(SalesAssistConfigurationEnum::AGENT_NAME->value);
        app(Apps::class)->del(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value);

        parent::tearDown();
    }

    public function testNewContactOpensChannelOnActiveLead(): void
    {
        $people = $this->createPerson();
        $activeLead = $this->createLead($people, self::ACTIVE_STATUS_ID);
        $newerClosedLead = $this->createLead($people, self::LOST_STATUS_ID);

        $phone = $this->uniquePhone();
        $people->addCellPhone($phone);

        $slug = SessionChannelService::createChannelSlug('sms', $phone);

        $this->assertTrue($activeLead->socialChannels()->where('slug', $slug)->exists());
        $this->assertFalse($newerClosedLead->socialChannels()->where('slug', $slug)->exists());
    }

    public function testNewContactFallsBackToLastLeadWhenNoneIsActive(): void
    {
        $people = $this->createPerson();
        $olderClosedLead = $this->createLead($people, self::LOST_STATUS_ID);
        $lastClosedLead = $this->createLead($people, self::LOST_STATUS_ID);

        $email = 'contact-observer-' . uniqid() . '@example.com';
        $people->addEmail($email);

        $slug = SessionChannelService::createChannelSlug('email', $email);

        $this->assertTrue($lastClosedLead->socialChannels()->where('slug', $slug)->exists());
        $this->assertFalse($olderClosedLead->socialChannels()->where('slug', $slug)->exists());
    }

    public function testNewLeadOpensChannelsForExistingContacts(): void
    {
        $people = $this->createPerson();
        $olderActiveLead = $this->createLead($people, self::ACTIVE_STATUS_ID);

        app(Apps::class)->del(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value);
        $phone = $this->uniquePhone();
        $people->addCellPhone($phone);
        app(Apps::class)->set(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value, true);

        $newLead = $this->createLead($people->fresh(), self::LOST_STATUS_ID);

        $slug = SessionChannelService::createChannelSlug('sms', $phone);

        $this->assertTrue($newLead->socialChannels()->where('slug', $slug)->exists());
        $this->assertFalse($olderActiveLead->socialChannels()->where('slug', $slug)->exists());
    }

    public function testSavingContactsAgainDoesNotDuplicateChannelsOrSessions(): void
    {
        $people = $this->createPerson();
        $lead = $this->createLead($people, self::ACTIVE_STATUS_ID);

        $contact = $people->addCellPhone($this->uniquePhone());
        $people->addEmail('contact-observer-' . uniqid() . '@example.com');

        $channels = $lead->socialChannels()->count();
        $sessions = $this->channelSessions($lead)->count();

        $contact->weight = 50;
        $contact->saveOrFail();
        $contact->contacts_types_id = ContactTypeEnum::PHONE->value;
        $contact->saveOrFail();
        $contact->contacts_types_id = ContactTypeEnum::CELLPHONE->value;
        $contact->saveOrFail();

        $this->assertSame($channels, $lead->socialChannels()->count());
        $this->assertSame($sessions, $this->channelSessions($lead)->count());
        $this->assertSame(
            $lead->socialChannels()->count(),
            $lead->socialChannels()->distinct()->count('slug')
        );
    }

    public function testSessionsAreKeyedToThePersonAndChannelsToTheLead(): void
    {
        $people = $this->createPerson();
        $lead = $this->createLead($people, self::ACTIVE_STATUS_ID);

        $phone = $this->uniquePhone();
        $people->addCellPhone($phone);

        $channel = $lead->socialChannels()
            ->where('slug', SessionChannelService::createChannelSlug('sms', $phone))
            ->firstOrFail();
        $session = Session::query()->where('channel_id', $channel->getId())->firstOrFail();

        $this->assertSame(People::class, $session->entity_namespace);
        $this->assertSame((int) $people->getId(), (int) $session->entity_id);
        $this->assertSame(0, $lead->aiSession()->count());
    }

    public function testReusesTheAgentTheLeadAlreadyTalksTo(): void
    {
        $people = $this->createPerson();
        $lead = $this->createLead($people, self::ACTIVE_STATUS_ID);
        $existingAgent = $this->createAgent('contact-observer-existing-' . uniqid());

        $people->addCellPhone($this->uniquePhone());

        $this->channelSessions($lead)->update(['agents_id' => $existingAgent->getId()]);
        $sessions = $this->channelSessions($lead)->count();

        $people->addEmail('contact-observer-' . uniqid() . '@example.com');

        $this->assertSame($sessions + 1, $this->channelSessions($lead)->count());
        $this->assertSame(
            [(int) $existingAgent->getId()],
            $this->channelSessions($lead)->distinct()->pluck('agents_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function testDoesNothingWhenTheAppSettingIsOff(): void
    {
        app(Apps::class)->del(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value);
        auth()->user()->getCurrentCompany()->set(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value, true);

        $this->assertFalse($this->channelOpensForNewContact());
    }

    public function testCompanyCanOptOutOfTheAppSetting(): void
    {
        auth()->user()->getCurrentCompany()->set(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value, false);

        $this->assertFalse($this->channelOpensForNewContact());
    }

    public function testDoesNothingWhenTheDefaultAgentDoesNotExist(): void
    {
        auth()->user()->getCurrentCompany()->set(
            SalesAssistConfigurationEnum::AGENT_NAME->value,
            'contact-observer-missing-' . uniqid()
        );

        $this->assertFalse($this->channelOpensForNewContact());
    }

    public function testContactWithoutLeadSavesWithoutChannels(): void
    {
        $people = $this->createPerson();

        $contact = $people->addCellPhone($this->uniquePhone());

        $this->assertTrue($contact->exists);
    }

    private function channelOpensForNewContact(): bool
    {
        $people = $this->createPerson();
        $lead = $this->createLead($people, self::ACTIVE_STATUS_ID);

        $phone = $this->uniquePhone();
        $people->addCellPhone($phone);

        return $lead->socialChannels()
            ->where('slug', SessionChannelService::createChannelSlug('sms', $phone))
            ->exists();
    }

    private function channelSessions(Lead $lead): Builder
    {
        return Session::query()->whereIn('channel_id', $lead->socialChannels()->pluck('id'));
    }

    private function createAgent(string $name): Agent
    {
        $app = app(Apps::class);

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron']);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'name' => $name,
            ]);
    }

    private function createPerson(): People
    {
        $user = auth()->user();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();

        return $people->fresh();
    }

    private function createLead(People $people, int $leadsStatusId): Lead
    {
        $user = auth()->user();

        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create(['leads_status_id' => $leadsStatusId]);
    }

    private function uniquePhone(): string
    {
        return '202' . random_int(2000000, 9999999);
    }
}
