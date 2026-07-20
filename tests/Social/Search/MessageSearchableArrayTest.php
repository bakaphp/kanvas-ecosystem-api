<?php

declare(strict_types=1);

namespace Tests\Social\Search;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

/**
 * The Message Typesense schema declares created_at/updated_at as int64. toArray() emits them as
 * ISO-8601 strings, which makes Typesense reject the import with "Field `updated_at` must be an
 * int64." (Sentry KANVAS-ECOSYSTEM-628). toSearchableArray() must cast them to Unix timestamps.
 */
class MessageSearchableArrayTest extends TestCase
{
    use DatabaseTransactions;

    public function testToSearchableArrayEmitsInt64Timestamps(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $messageType = MessageType::factory()->create();

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageTypeId($messageType->getId())
            ->create(['is_public' => 1]);

        $doc = $message->fresh()->toSearchableArray();

        $this->assertIsInt($doc['created_at'], 'created_at must be an int64 timestamp, not a date string.');
        $this->assertIsInt($doc['updated_at'], 'updated_at must be an int64 timestamp, not a date string.');
        $this->assertSame($message->created_at->getTimestamp(), $doc['created_at']);
        $this->assertSame($message->updated_at->getTimestamp(), $doc['updated_at']);
    }
}
