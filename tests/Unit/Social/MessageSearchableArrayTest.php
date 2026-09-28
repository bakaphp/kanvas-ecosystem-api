<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Tests\TestCaseUnit;

class MessageSearchableArrayTest extends TestCaseUnit
{
    /**
     * Regression: the Typesense schema declares is_premium, is_locked and is_public
     * as `bool`. toArray()/raw attributes surface them as ints, which Typesense rejects
     * with "Field `is_premium` must be a bool." (Sentry KANVAS-ECOSYSTEM-628).
     */
    public function testBoolSchemaFieldsAreEmittedAsBooleans(): void
    {
        $message = new Message();
        $message->setRawAttributes([
            'id' => 1,
            'uuid' => 'test-uuid',
            'users_id' => 10,
            'parent_id' => 99, // set so the children DB query is skipped
            'message' => 'hello',
            'is_public' => 1,
            'is_premium' => 1,
            'is_locked' => 0,
            'is_deleted' => 0,
        ]);

        $message->setRelation('user', new Users(['firstname' => 'Jane', 'lastname' => 'Doe', 'displayname' => 'jane']));
        $message->setRelation('messageType', new MessageType(['name' => 'post', 'verb' => 'post']));
        $message->setRelation('parent', null);

        $data = $message->toSearchableArray();

        $this->assertIsBool($data['is_public']);
        $this->assertIsBool($data['is_premium']);
        $this->assertIsBool($data['is_locked']);
        $this->assertIsBool($data['is_deleted']);

        $this->assertTrue($data['is_public']);
        $this->assertTrue($data['is_premium']);
        $this->assertFalse($data['is_locked']);
        $this->assertFalse($data['is_deleted']);
    }

    /**
     * Regression: the Typesense schema declares `message` as `object`. Legacy bodies can be a plain
     * string, which Typesense rejects with "Field `message` has an incorrect type."
     * (Sentry KANVAS-ECOSYSTEM-628). toSearchableArray() must coerce it to an object.
     */
    public function testMessageFieldIsAlwaysAnObject(): void
    {
        $plainStringBody = $this->searchableMessageFor('just a plain text body');
        $this->assertIsObject($plainStringBody['message']);
        $this->assertSame('just a plain text body', $plainStringBody['message']->content);

        $jsonObjectBody = $this->searchableMessageFor('{"content":"hello world","extra":1}');
        $this->assertIsObject($jsonObjectBody['message']);
        $this->assertSame('hello world', $jsonObjectBody['message']->content);

        $emptyBody = $this->searchableMessageFor('');
        $this->assertIsObject($emptyBody['message']);
        // Empty body must serialize to `{}` (object), never `[]` (array), or Typesense rejects it.
        $this->assertSame('{}', json_encode($emptyBody['message']));
    }

    /**
     * Regression: a collection that auto-typed `message` as a string can't be re-typed, so the
     * object fails the whole import batch with "Field `message` must be a string."
     * (Sentry KANVAS-ECOSYSTEM-628).
     */
    public function testMessageFieldIsAStringWhenTheLiveCollectionTypedItAsOne(): void
    {
        $message = $this->searchableMessageModel('{"content":"hello world","from_me":true}');
        $this->fakeCollectionSchema($message, [['name' => 'message', 'type' => 'string']]);

        $data = $message->toSearchableArray();

        $this->assertIsString($data['message']);
        $this->assertSame('hello world', $data['message']);
    }

    public function testMessageFieldStaysAnObjectWhenTheLiveCollectionDeclaresIt(): void
    {
        $message = $this->searchableMessageModel('{"content":"hello world","from_me":true}');
        $this->fakeCollectionSchema($message, [['name' => 'message', 'type' => 'object']]);

        $data = $message->toSearchableArray();

        $this->assertIsObject($data['message']);
        $this->assertSame('hello world', $data['message']->content);
    }

    private function fakeCollectionSchema(Message $message, array $fields, bool $nestedFields = true): void
    {
        $message->setTypesense();

        Cache::put(
            'typesense_collection_schema_' . app(Apps::class)->getId() . '_' . $message->searchableAs(),
            [
                'fields' => $fields,
                'enable_nested_fields' => $nestedFields,
            ],
            60
        );
    }

    private function searchableMessageModel(string $rawMessage): Message
    {
        $message = new Message();
        $message->setRawAttributes([
            'id' => 1,
            'uuid' => 'test-uuid',
            'users_id' => 10,
            'parent_id' => 99, // set so the children DB query is skipped
            'message' => $rawMessage,
            'is_public' => 1,
            'is_premium' => 0,
            'is_locked' => 0,
            'is_deleted' => 0,
        ], true); // sync original so getRawOriginal('message') behaves like a DB-loaded model

        $message->setRelation('app', app(Apps::class));
        $message->setRelation('user', new Users(['firstname' => 'Jane', 'lastname' => 'Doe', 'displayname' => 'jane']));
        $message->setRelation('messageType', new MessageType(['name' => 'post', 'verb' => 'post']));
        $message->setRelation('parent', null);

        return $message;
    }

    /**
     * Regression: `message` is declared `object`, so Typesense types every nested key from the first
     * document it indexes. An envelope holding a LIST of records then collides with the scalar types
     * a single-record reply established — "field inside an array of objects must be an array type as
     * well" — and the import is rejected.
     */
    public function testTheAgentEnvelopeIsKeptOutOfTheSearchPayload(): void
    {
        $body = json_encode([
            'content' => 'The reply that was sent.',
            'from_ia' => true,
            'response_json' => [
                ['title' => 'First article', 'status' => 'draft'],
                ['title' => 'Second article', 'status' => 'draft'],
            ],
        ]);

        $data = $this->searchableMessageFor((string) $body);

        $this->assertObjectNotHasProperty('response_json', $data['message']);
        $this->assertSame('The reply that was sent.', $data['message']->content);
        $this->assertSame('The reply that was sent.', $data['message_text']);
    }

    private function searchableMessageFor(string $rawMessage): array
    {
        return $this->searchableMessageModel($rawMessage)->toSearchableArray();
    }
}
