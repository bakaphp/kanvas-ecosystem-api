<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Traits;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Kanvas\Social\Messages\Models\Message;

trait HasChronologicalFeed
{
    protected function chronologicalFeed(
        AppInterface $app,
        int $page,
        int $pageSize,
        int $totalRecords,
        mixed $messageTypeId,
    ): LengthAwarePaginator {
        $messages = Message::fromApp($app)
            ->where('is_public', 1)
            ->where('is_deleted', 0)
            ->when($messageTypeId !== null, function (Builder $query) use ($messageTypeId): Builder {
                return $query->where('messages.message_types_id', $messageTypeId);
            })
            ->orderBy('messages.created_at', 'desc')
            ->forPage($page, $pageSize)
            ->get();

        return new LengthAwarePaginator(
            $messages,
            $totalRecords,
            $pageSize,
            $page
        );
    }
}
