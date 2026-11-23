<?php

declare(strict_types=1);

namespace Tests\Social\Integration;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

final class MessagePathRebuildTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social'];

    /**
     * The range update is what deadlocked parallel inbound deliveries: it gap-locks the path index
     * right where the next message is being inserted.
     */
    public function testAMessageWithNoRepliesIsReparentedWithoutARangeUpdate(): void
    {
        $head = $this->message();
        $part = $this->message();
        $updates = $this->recordPathUpdates();

        $part->parent_id = $head->getId();
        $part->saveOrFail();

        $this->assertSame($head->getId() . '.' . $part->getId(), $this->pathOf($part));
        $this->assertSame([], array_filter($updates(), fn (string $sql): bool => str_contains($sql, ' like ')));
    }

    public function testAMessageWithRepliesStillCarriesItsSubtree(): void
    {
        $head = $this->message();
        $part = $this->message();
        $reply = $this->message();

        $reply->parent_id = $part->getId();
        $reply->saveOrFail();

        $part->parent_id = $head->getId();
        $part->saveOrFail();

        $this->assertSame($head->getId() . '.' . $part->getId(), $this->pathOf($part));
        $this->assertSame($head->getId() . '.' . $part->getId() . '.' . $reply->getId(), $this->pathOf($reply));
    }

    private function message(): Message
    {
        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['message' => ['content' => 'part']]);
    }

    private function pathOf(Message $message): string
    {
        return (string) DB::connection('social')
            ->table('messages')
            ->where('id', $message->getId())
            ->value('path');
    }

    /**
     * @return callable(): list<string>
     */
    private function recordPathUpdates(): callable
    {
        $queries = [];

        DB::connection('social')->listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with($query->sql, 'update `messages` set `path`')) {
                $queries[] = $query->sql;
            }
        });

        return fn (): array => $queries;
    }
}
