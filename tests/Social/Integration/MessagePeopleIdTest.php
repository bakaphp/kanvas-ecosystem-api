<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Tests\TestCase;

class MessagePeopleIdTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm'];

    private Apps $kanvasApp;
    private MessageType $messageType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->messageType = MessageType::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'verb' => 'sms-' . fake()->unique()->lexify('????'),
        ]);
    }

    public function testDerivesPeopleIdFromAnAssociatedLead(): void
    {
        $lead = $this->createLead();

        $message = $this->createMessage($lead);

        $this->assertSame((int) $lead->people_id, (int) $message->refresh()->people_id);
    }

    public function testDerivesPeopleIdFromAnAssociatedPeople(): void
    {
        $lead = $this->createLead();
        $people = People::getById((int) $lead->people_id);

        $message = $this->createMessage($people);

        $this->assertSame($people->getId(), (int) $message->refresh()->people_id);
    }

    public function testAnExplicitPeopleOnTheInputWinsOverTheEntity(): void
    {
        $lead = $this->createLead();
        // A bare People, not a second lead — each Lead::factory() fires LeadObserver ->
        // CreateChannelAction inside a transaction, and that contention deadlocks under CI.
        $otherPeople = People::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();

        $message = $this->createMessage($lead, $otherPeople);

        $this->assertSame($otherPeople->getId(), (int) $message->refresh()->people_id);
    }

    public function testLeavesPeopleIdNullWhenNoPersonIsInScope(): void
    {
        $message = $this->createMessage();

        $this->assertNull($message->refresh()->people_id);
    }

    public function testLeavesPeopleIdNullOnANonCommunicationMessageEvenWithALeadInScope(): void
    {
        $lead = $this->createLead();

        // No `from_me` key -> not a communication message -> sender_type stays NULL.
        $message = $this->createMessage($lead, payload: ['message' => 'an internal note', 'params' => []]);

        $this->assertNull($message->refresh()->sender_type);
        $this->assertNull($message->people_id);
    }

    public function testAnExplicitPeopleIsStillIgnoredOnANonCommunicationMessage(): void
    {
        $lead = $this->createLead();
        $people = People::getById((int) $lead->people_id);

        $message = $this->createMessage(null, $people, ['message' => 'an internal note']);

        $this->assertNull($message->refresh()->people_id);
    }

    public function testBackfillClearsPeopleIdFromNonCommunicationRows(): void
    {
        $lead = $this->createLead();
        $message = $this->createMessage($lead, payload: ['message' => 'an internal note', 'params' => []]);

        // A row left behind by the earlier, looser backfill.
        DB::connection('social')->table('messages')
            ->where('id', $message->getId())
            ->update(['people_id' => (int) $lead->people_id]);

        Artisan::call('kanvas:social:backfill-message-people-id', [
            '--from-id' => $message->getId() - 1,
            '--app' => $this->kanvasApp->getId(),
        ]);

        $this->assertNull($message->refresh()->people_id);
    }

    public function testLeavesPeopleIdNullOnAnAiChatMessageEvenWithALeadInScope(): void
    {
        $lead = $this->createLead();
        $aiChat = MessageType::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'verb' => 'ai-chat',
        ]);

        // ai-chat payloads carry from_me, so this row DOES get a sender_type — but it is an in-app
        // assistant turn, not a customer conversation.
        $message = $this->createMessage($lead, type: $aiChat);

        $this->assertNotNull($message->refresh()->sender_type);
        $this->assertNull($message->people_id);
    }

    public function testBackfillClearsPeopleIdFromANonCommunicationChannel(): void
    {
        $lead = $this->createLead();
        $aiChat = MessageType::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'verb' => 'ai-control',
        ]);
        $message = $this->createMessage($lead, type: $aiChat);

        // A row left behind by the earlier version that gated on sender_type alone.
        DB::connection('social')->table('messages')
            ->where('id', $message->getId())
            ->update(['people_id' => (int) $lead->people_id]);

        Artisan::call('kanvas:social:backfill-message-people-id', [
            '--from-id' => $message->getId() - 1,
            '--app' => $this->kanvasApp->getId(),
        ]);

        $this->assertNull($message->refresh()->people_id);
    }

    public function testBackfillPopulatesHistoricalMessagesFromTheirLead(): void
    {
        $lead = $this->createLead();
        $message = $this->createMessage($lead);

        // Simulate a row written before the column existed.
        DB::connection('social')->table('messages')
            ->where('id', $message->getId())
            ->update(['people_id' => null]);

        Artisan::call('kanvas:social:backfill-message-people-id', [
            '--from-id' => $message->getId() - 1,
            '--app' => $this->kanvasApp->getId(),
        ]);

        $this->assertSame((int) $lead->people_id, (int) $message->refresh()->people_id);
    }

    private function createLead(): Lead
    {
        return Lead::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function createMessage(
        mixed $entity = null,
        ?People $people = null,
        ?array $payload = null,
        ?MessageType $type = null,
    ): Message {
        $user = auth()->user();

        $input = new MessageInput(
            app: $this->kanvasApp,
            company: $user->getCurrentCompany(),
            user: $user,
            type: $type ?? $this->messageType,
            message: $payload ?? ['content' => 'hi', 'from_me' => false],
            people: $people,
        );

        if ($entity === null) {
            return new CreateMessageAction($input)->execute();
        }

        return new CreateMessageAction(
            $input,
            SystemModulesRepository::getByModelName($entity::class, $this->kanvasApp),
            $entity->getId(),
        )->execute();
    }
}
