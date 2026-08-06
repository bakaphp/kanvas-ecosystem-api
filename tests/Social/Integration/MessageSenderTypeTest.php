<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MessageSenderTypeTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: ?string}>
     */
    public static function payloadProvider(): array
    {
        return [
            'human outbound' => [['content' => 'hi', 'from_me' => true, 'from_ia' => false], MessageSenderTypeEnum::USER->value],
            'human outbound (no from_ia key)' => [['content' => 'hi', 'from_me' => true], MessageSenderTypeEnum::USER->value],
            'ai outbound' => [['content' => 'hi', 'from_me' => true, 'from_ia' => true], MessageSenderTypeEnum::AGENT->value],
            'orchestrator outbound' => [['content' => 'hi', 'from_me' => true, 'from_orchestrator' => true], MessageSenderTypeEnum::AGENT->value],
            'customer inbound' => [['content' => 'hi', 'from_me' => false], MessageSenderTypeEnum::CONTACT->value],
            'legacy int booleans' => [['content' => 'hi', 'from_me' => 1, 'from_ia' => 1], MessageSenderTypeEnum::AGENT->value],
            'legacy string booleans' => [['content' => 'hi', 'from_me' => 'true', 'from_ia' => 'false'], MessageSenderTypeEnum::USER->value],
            'non-communication (no from_me)' => [['message' => 'a social post', 'params' => []], null],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('payloadProvider')]
    public function testEnumClassifiesPayload(array $payload, ?string $expected): void
    {
        $this->assertSame($expected, MessageSenderTypeEnum::fromPayload($payload)?->value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('payloadProvider')]
    public function testObserverPersistsSenderType(array $payload, ?string $expected): void
    {
        $message = Message::factory()->create(['message' => $payload]);

        $this->assertSame($expected, $message->refresh()->sender_type);
    }

    public function testObserverResyncsWhenPayloadChanges(): void
    {
        $message = Message::factory()->create(['message' => ['content' => 'hi', 'from_me' => false]]);
        $this->assertSame(MessageSenderTypeEnum::CONTACT->value, $message->refresh()->sender_type);

        $message->message = ['content' => 'reply', 'from_me' => true, 'from_ia' => true];
        $message->saveOrFail();

        $this->assertSame(MessageSenderTypeEnum::AGENT->value, $message->refresh()->sender_type);
    }

    public function testBackfillCommandPopulatesHistoricRows(): void
    {
        $app = app(Apps::class);

        $human = Message::factory()->create(['message' => ['content' => 'hi', 'from_me' => true]]);
        $agent = Message::factory()->create(['message' => ['content' => 'hi', 'from_me' => true, 'from_ia' => true]]);
        $contact = Message::factory()->create(['message' => ['content' => 'hi', 'from_me' => false]]);

        // Simulate pre-migration history: wipe the column the observer just set, without events.
        $ids = [$human->id, $agent->id, $contact->id];
        DB::connection('social')->table('messages')->whereIn('id', $ids)->update(['sender_type' => null]);

        Artisan::call('kanvas:social:backfill-message-sender-type', [
            '--app' => $app->getId(),
            '--from-id' => min($ids) - 1,
        ]);

        $this->assertSame(MessageSenderTypeEnum::USER->value, $human->refresh()->sender_type);
        $this->assertSame(MessageSenderTypeEnum::AGENT->value, $agent->refresh()->sender_type);
        $this->assertSame(MessageSenderTypeEnum::CONTACT->value, $contact->refresh()->sender_type);
    }
}
