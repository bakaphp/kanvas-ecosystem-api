<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

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
}
