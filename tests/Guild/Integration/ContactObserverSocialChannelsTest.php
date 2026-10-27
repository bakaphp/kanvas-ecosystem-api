<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums\ConfigurationEnum as MetaConfigurationEnum;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Handlers\MetaWhatsAppHandler;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum as SalesAssistConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Sessions\Models\Session as SessionModel;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

// Turns an app setting on, which every paratest process shares through Redis.
#[Group('serial')]
final class ContactObserverSocialChannelsTest extends TestCase
{
    private const int ACTIVE_STATUS_ID = 1;
    private const int LOST_STATUS_ID = 9;

    private ?IntegrationsCompany $whatsAppIntegration = null;

    private ?Integrations $createdIntegration = null;

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
        $company->del(MetaConfigurationEnum::PHONE_NUMBER_ID->value);
        // Not in a transaction (the observers write on several connections), so the row goes by hand.
        $this->whatsAppIntegration?->delete();
        $this->createdIntegration?->delete();

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

        app(Apps::class)->set(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value, false);
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
        $sessions = $lead->aiSession()->count();

        $contact->weight = 50;
        $contact->saveOrFail();
        $contact->contacts_types_id = ContactTypeEnum::PHONE->value;
        $contact->saveOrFail();
        $contact->contacts_types_id = ContactTypeEnum::CELLPHONE->value;
        $contact->saveOrFail();

        $this->assertSame($channels, $lead->socialChannels()->count());
        $this->assertSame($sessions, $lead->aiSession()->count());
        $this->assertSame(
            $lead->socialChannels()->count(),
            $lead->socialChannels()->distinct()->count('slug')
        );
    }

    public function testReusesTheAgentTheLeadAlreadyTalksTo(): void
    {
        $people = $this->createPerson();
        $lead = $this->createLead($people, self::ACTIVE_STATUS_ID);
        $existingAgent = $this->createAgent('contact-observer-existing-' . uniqid());

        $people->addCellPhone($this->uniquePhone());

        $lead->aiSession()->update(['agents_id' => $existingAgent->getId()]);
        $sessions = $lead->aiSession()->count();

        $people->addEmail('contact-observer-' . uniqid() . '@example.com');

        $this->assertSame($sessions + 1, $lead->aiSession()->count());
        $this->assertSame(
            [(int) $existingAgent->getId()],
            $lead->aiSession()->distinct()->pluck('agents_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function testOnWhenNothingIsConfigured(): void
    {
        app(Apps::class)->del(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value);

        $this->assertTrue($this->channelOpensForNewContact());
    }

    public function testAppCanSwitchItOff(): void
    {
        app(Apps::class)->set(ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value, false);
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

    public function testCellphoneOpensAWhatsAppChannelWhenTheIntegrationIsOn(): void
    {
        $this->turnOnWhatsAppIntegration();
        $people = $this->createPerson();
        $lead = $this->createLead($people, self::ACTIVE_STATUS_ID);

        $phone = $this->uniquePhone();
        $people->addCellPhone($phone);

        $this->assertTrue($lead->socialChannels()->where('slug', SessionChannelService::createChannelSlug('sms', $phone))->exists());
        $this->assertFalse($lead->socialChannels()->where('slug', SessionChannelService::createChannelSlug('whatsapp', $phone))->exists());
        $this->assertTrue($this->hasWhatsAppConversation($people, $phone));
    }

    public function testAPersonWithoutALeadStillGetsTheirWhatsAppConversation(): void
    {
        $this->turnOnWhatsAppIntegration();
        $people = $this->createPerson();

        $phone = $this->uniquePhone();
        $people->addCellPhone($phone);

        $this->assertTrue($this->hasWhatsAppConversation($people, $phone));
        $this->assertFalse($people->socialChannels()->where('slug', SessionChannelService::createChannelSlug('sms', $phone))->exists());
    }

    public function testUpdatedCellphoneOpensAWhatsAppChannelForTheNewNumber(): void
    {
        $this->turnOnWhatsAppIntegration();
        $people = $this->createPerson();
        $this->createLead($people, self::ACTIVE_STATUS_ID);
        $contact = $people->addCellPhone($this->uniquePhone());

        $newPhone = $this->uniquePhone();
        $contact->value = $newPhone;
        $contact->saveOrFail();

        $this->assertTrue($this->hasWhatsAppConversation($people, $newPhone));
    }

    public function testNoWhatsAppChannelWithoutTheIntegration(): void
    {
        $people = $this->createPerson();
        $this->createLead($people, self::ACTIVE_STATUS_ID);

        $phone = $this->uniquePhone();
        $people->addCellPhone($phone);

        $this->assertFalse($people->socialChannels()->where('slug', SessionChannelService::createChannelSlug('whatsapp', $phone))->exists());
    }

    public function testNoWhatsAppChannelWhileTheIntegrationIsSwitchedOff(): void
    {
        $this->turnOnWhatsAppIntegration()->isActive(false);
        $people = $this->createPerson();
        $this->createLead($people, self::ACTIVE_STATUS_ID);

        $phone = $this->uniquePhone();
        $people->addCellPhone($phone);

        $this->assertFalse($people->socialChannels()->where('slug', SessionChannelService::createChannelSlug('whatsapp', $phone))->exists());
    }

    public function testOnlyACellphoneOpensAWhatsAppChannel(): void
    {
        $this->turnOnWhatsAppIntegration();
        $people = $this->createPerson();
        $this->createLead($people, self::ACTIVE_STATUS_ID);

        $people->addEmail('contact-observer-' . uniqid() . '@example.com');
        $people->addPhone($this->uniquePhone());

        $this->assertFalse($people->socialChannels()->where('slug', 'like', 'wa-chat-%')->exists());
    }

    private function turnOnWhatsAppIntegration(): IntegrationsCompany
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $integration = Integrations::firstOrCreate(
            ['name' => IntegrationsEnum::META_WHATSAPP->value],
            ['apps_id' => 0, 'config' => [], 'handler' => MetaWhatsAppHandler::class]
        );
        $this->createdIntegration = $integration->wasRecentlyCreated ? $integration : null;
        $company->set(MetaConfigurationEnum::PHONE_NUMBER_ID->value, '106540352242922');

        return $this->whatsAppIntegration = IntegrationsCompany::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'integrations_id' => $integration->getId(),
            'region_id' => Regions::getDefault($company, $app)->getId(),
            'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
        ]);
    }

    private function hasWhatsAppConversation(People $people, string $phone): bool
    {
        $channel = $people->socialChannels()
            ->where('slug', SessionChannelService::createChannelSlug('whatsapp', $phone))
            ->first();

        return $channel !== null && SessionModel::query()
            ->where('entity_namespace', People::class)
            ->where('entity_id', (string) $people->getId())
            ->where('canal_id', SessionChannelService::createCanalId('whatsapp', $phone))
            ->exists();
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
