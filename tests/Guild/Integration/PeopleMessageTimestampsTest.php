<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Listeners\UpdatePeopleMessageTimestampsListener;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Events\AppModuleMessageCreatedEvent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

final class PeopleMessageTimestampsTest extends TestCase
{
    private ?MessageType $communicationType = null;
    private ?MessageType $nonCommunicationType = null;

    public function testFirstMessageStampsBothColumns(): void
    {
        [$people, $lead] = $this->createPeopleAndLead();

        $this->assertNull($people->first_message_at);
        $this->assertNull($people->last_message_at);

        $message = $this->createMessageForLead($lead);

        $people->refresh();

        $this->assertNotNull(
            $people->first_message_at,
            'first_message_at must be set when the first message is attached to a lead',
        );
        $this->assertNotNull(
            $people->last_message_at,
            'last_message_at must be set when the first message is attached to a lead',
        );
        $this->assertEquals(
            $message->appModuleMessage->created_at->format('Y-m-d H:i:s'),
            $people->first_message_at->format('Y-m-d H:i:s'),
        );
    }

    public function testSecondMessageOnlyAdvancesLastMessageAt(): void
    {
        [$people, $lead] = $this->createPeopleAndLead();

        $this->createMessageForLead($lead);
        $people->refresh();
        $firstStamp = $people->first_message_at;
        $this->assertNotNull($firstStamp);

        // Make sure clock moves forward at least 1 second so MariaDB can distinguish timestamps.
        sleep(1);

        $this->createMessageForLead($lead);
        $people->refresh();

        $this->assertEquals(
            $firstStamp->format('Y-m-d H:i:s'),
            $people->first_message_at->format('Y-m-d H:i:s'),
            'first_message_at must not move when subsequent messages arrive',
        );
        $this->assertTrue(
            $people->last_message_at->gt($firstStamp),
            'last_message_at must advance when a newer message arrives',
        );
    }

    public function testNonCommunicationMessageIsIgnored(): void
    {
        [$people, $lead] = $this->createPeopleAndLead();

        // A message whose type verb is not a communication channel (e.g. an internal
        // note) must NOT stamp the people timestamps — this is the isCommunicationMessage guard.
        $message = $this->makeMessage(
            $lead,
            $this->nonCommunicationMessageType(),
            $lead->apps_id,
            $lead->companies_id,
        );
        $this->runListener($message);

        $people->refresh();

        $this->assertNull(
            $people->first_message_at,
            'Non-communication messages must not stamp first_message_at',
        );
        $this->assertNull(
            $people->last_message_at,
            'Non-communication messages must not stamp last_message_at',
        );
    }

    public function testNonLeadEntityDoesNotTouchPeople(): void
    {
        [$people, ] = $this->createPeopleAndLead();

        // Communication message, but attached to People itself (not a Lead) — the
        // system_modules guard must keep the listener from stamping anything.
        $message = $this->makeMessage(
            $people,
            $this->communicationMessageType(),
            $people->apps_id,
            $people->companies_id,
        );
        $this->runListener($message);

        $people->refresh();

        $this->assertNull(
            $people->first_message_at,
            'Messages attached to entities other than Lead must not stamp people',
        );
        $this->assertNull(
            $people->last_message_at,
            'Messages attached to entities other than Lead must not stamp people',
        );
    }

    public function testInboundCustomerMessageMarksPeopleUnread(): void
    {
        [$people, $lead] = $this->createPeopleAndLead();
        $people->set('unread_message', 0);

        // Inbound customer reply: neither from_me nor from_ia — the rep must see it.
        $message = $this->makeMessage(
            $lead,
            $this->communicationMessageType(),
            $lead->apps_id,
            $lead->companies_id,
            ['content' => 'Thanks for helping me.', 'from_me' => false, 'from_ia' => false],
        );
        $this->runListener($message);

        $this->assertSame(
            1,
            (int) $people->get('unread_message'),
            'An inbound customer message must mark the people record as unread',
        );
    }

    public function testOutboundMessageDoesNotMarkUnread(): void
    {
        [$people, $lead] = $this->createPeopleAndLead();
        $people->set('unread_message', 0);

        // Our own outbound reply carries from_me=true — it must not flip the flag.
        $message = $this->makeMessage(
            $lead,
            $this->communicationMessageType(),
            $lead->apps_id,
            $lead->companies_id,
            ['content' => 'How can I help?', 'from_me' => true, 'from_ia' => false],
        );
        $this->runListener($message);

        $this->assertSame(
            0,
            (int) $people->get('unread_message'),
            'An outbound (from_me) message must not mark the people record as unread',
        );
    }

    public function testHumanRepMessageToCustomerDoesNotMarkUnread(): void
    {
        [$people, $lead] = $this->createPeopleAndLead();
        $people->set('unread_message', 0);

        // A human rep typing to the customer: from_human=true, from_me=true, no from_ia
        // key at all. It is still our side (from_me) so it must not flip the flag.
        $message = $this->makeMessage(
            $lead,
            $this->communicationMessageType(),
            $lead->apps_id,
            $lead->companies_id,
            ['content' => 'I spoke with Owen...', 'from_human' => true, 'from_me' => true],
        );
        $this->runListener($message);

        $this->assertSame(
            0,
            (int) $people->get('unread_message'),
            'A human rep message to the customer (from_me) must not mark the people record as unread',
        );
    }

    public function testAgentMessageDoesNotMarkUnread(): void
    {
        [$people, $lead] = $this->createPeopleAndLead();
        $people->set('unread_message', 0);

        // AI/agent authored reply carries from_ia=true — it must not flip the flag.
        $message = $this->makeMessage(
            $lead,
            $this->communicationMessageType(),
            $lead->apps_id,
            $lead->companies_id,
            ['content' => 'Sure, I can assist.', 'from_me' => true, 'from_ia' => true],
        );
        $this->runListener($message);

        $this->assertSame(
            0,
            (int) $people->get('unread_message'),
            'An AI/agent (from_ia) message must not mark the people record as unread',
        );
    }

    public function testPeoplesQueryCanOrderByLastMessageAt(): void
    {
        [$older, ] = $this->createPeopleAndLead();
        [$newer, ] = $this->createPeopleAndLead();

        $marker = 'ts-' . uniqid('', true);
        $older->forceFill([
            'firstname' => $marker . '-A',
            'first_message_at' => Carbon::now()->subDays(10),
            'last_message_at' => Carbon::now()->subDays(10),
        ])->saveQuietly();
        $newer->forceFill([
            'firstname' => $marker . '-B',
            'first_message_at' => Carbon::now()->subDays(1),
            'last_message_at' => Carbon::now()->subDays(1),
        ])->saveQuietly();

        $raw = $this->graphQL(
            'query($firstname: Mixed) {
                peoples(
                    where: { column: FIRSTNAME, operator: LIKE, value: $firstname }
                    orderBy: [{ column: LAST_MESSAGE_AT, order: DESC }]
                ) {
                    data { id last_message_at }
                }
            }',
            ['firstname' => $marker . '%'],
        )->assertOk()->json();
        $response = $raw['data']['peoples']['data'] ?? null;
        $this->assertIsArray(
            $response,
            'peoples query must return data, got: ' . json_encode($raw),
        );
        $ids = array_map(fn ($row) => (int) $row['id'], $response);

        $this->assertSame(
            [$newer->getId(), $older->getId()],
            $ids,
            'peoples must order by last_message_at DESC',
        );
    }

    /**
     * @return array{0: People, 1: Lead}
     */
    private function createPeopleAndLead(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts()
            ->create();

        /** @var Lead $lead */
        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create();

        return [$people->fresh(), $lead];
    }

    private function createMessageForLead(Lead $lead): Message
    {
        $message = $this->makeMessage(
            $lead,
            $this->communicationMessageType(),
            $lead->apps_id,
            $lead->companies_id,
        );
        $this->runListener($message);

        return $message;
    }

    private function makeMessage(
        Model $entity,
        MessageType $type,
        int $appsId,
        int $companiesId,
        array $messagePayload = [],
    ): Message {
        $attributes = [
            'apps_id' => $appsId,
            'companies_id' => $companiesId,
            'message_types_id' => $type->getId(),
        ];

        if ($messagePayload !== []) {
            $attributes['message'] = $messagePayload;
        }

        $message = Message::factory()->create($attributes);
        $message->addEntity($entity);

        return $message->fresh('appModuleMessage');
    }

    private function runListener(Message $message): void
    {
        new UpdatePeopleMessageTimestampsListener()
            ->handle(new AppModuleMessageCreatedEvent($message->appModuleMessage));
    }

    private function communicationMessageType(): MessageType
    {
        return $this->communicationType ??= MessageType::factory()->create([
            'verb' => 'whatsapp-text-' . uniqid('', false),
        ]);
    }

    private function nonCommunicationMessageType(): MessageType
    {
        return $this->nonCommunicationType ??= MessageType::factory()->create([
            'verb' => 'note-' . uniqid('', false),
        ]);
    }
}
