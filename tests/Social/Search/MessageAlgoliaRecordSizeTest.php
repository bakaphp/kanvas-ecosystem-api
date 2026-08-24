<?php

declare(strict_types=1);

namespace Tests\Social\Search;

use Kanvas\Social\Messages\Models\Message;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Algolia rejects the whole batch when one record is over the plan cap, and an AI answer stored
 * as a message body blows past it on its own (Sentry KANVAS-ECOSYSTEM-5TG).
 */
class MessageAlgoliaRecordSizeTest extends TestCase
{
    private function trim(array $message): array
    {
        $method = new ReflectionMethod(Message::class, 'fitWithinAlgoliaRecordLimit');

        return $method->invoke(new Message(), $message);
    }

    private function oversizedMessage(): array
    {
        $body = str_repeat('a very long generated answer ', 4000);

        return [
            'objectID' => 'message-uuid',
            'id' => '713887',
            'uuid' => 'message-uuid',
            'apps_id' => 1,
            'companies_id' => 1,
            'users_id' => 234107,
            'message' => (object) ['content' => $body, 'model' => 'gemini-3.6-flash'],
            'message_text' => $body,
            'user' => ['id' => 234107, 'name' => 'Max Castro', 'displayname' => 'max'],
            'message_type' => ['id' => 1, 'name' => 'prompt', 'verb' => 'prompt'],
            'children' => array_fill(0, 5, [
                'id' => 1,
                'uuid' => 'child-uuid',
                'message' => (object) ['content' => str_repeat('child answer ', 500)],
                'user' => ['id' => 1, 'name' => 'Someone', 'displayname' => 'someone'],
            ]),
            'has_children' => true,
            'is_public' => true,
            'created_at' => 1755314000,
        ];
    }

    public function testOversizedMessageFitsUnderTheLimit(): void
    {
        config(['scout.algolia.record_size_limit' => 9500]);

        $original = $this->oversizedMessage();
        $this->assertGreaterThan(9500, strlen((string) json_encode($original)), 'Fixture must start oversized.');

        $trimmed = $this->trim($original);

        $this->assertLessThanOrEqual(9500, strlen((string) json_encode($trimmed)));
    }

    public function testIdentityAndSearchableTextSurviveTrimming(): void
    {
        config(['scout.algolia.record_size_limit' => 9500]);

        $trimmed = $this->trim($this->oversizedMessage());

        $this->assertSame('message-uuid', $trimmed['objectID'], 'Losing the objectID would orphan the record.');
        $this->assertSame('713887', $trimmed['id']);
        $this->assertSame(234107, $trimmed['users_id']);
        $this->assertNotEmpty($trimmed['message_text'], 'The record must stay searchable.');
        $this->assertIsObject($trimmed['message'], 'The message field stays an object.');
    }

    public function testThreadPreviewsAreSacrificedBeforeTheBody(): void
    {
        config(['scout.algolia.record_size_limit' => 9500]);

        // Only the children push this over budget — the body itself fits comfortably.
        $message = [
            'objectID' => 'm',
            'message' => (object) ['content' => 'short question'],
            'message_text' => 'short question',
            'children' => array_fill(0, 5, [
                'id' => 1,
                'message' => (object) ['content' => str_repeat('child answer ', 400)],
            ]),
        ];
        $this->assertGreaterThan(9500, strlen((string) json_encode($message)));

        $trimmed = $this->trim($message);

        $this->assertSame('short question', $trimmed['message_text'], 'The body is untouched.');
        $this->assertLessThanOrEqual(9500, strlen((string) json_encode($trimmed)));
    }

    public function testRecordSizeLimitIsConfigurable(): void
    {
        $original = $this->oversizedMessage();

        config(['scout.algolia.record_size_limit' => 500000]);
        $this->assertEquals($original, $this->trim($original), 'A raised budget must leave the record untouched.');

        config(['scout.algolia.record_size_limit' => 9500]);
        $this->assertNotEquals($original, $this->trim($original));
    }

    public function testSmallMessageIsLeftUntouched(): void
    {
        config(['scout.algolia.record_size_limit' => 9500]);

        $small = [
            'objectID' => 'm1',
            'message' => (object) ['content' => 'hello'],
            'message_text' => 'hello',
            'children' => [['id' => 1, 'message' => (object) ['content' => 'hi']]],
        ];

        $this->assertEquals($small, $this->trim($small));
    }
}
