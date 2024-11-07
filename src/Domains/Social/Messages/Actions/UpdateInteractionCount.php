<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;

class UpdateInteractionCount
{
    public function __construct(
        protected Message $message
    ) {
    }

    /**
     * Update all interaction counts for the message using a single query
     */
    public function execute(): void
    {
        $baseQuery = UserMessage::fromApp($this->message->app)
            ->where('messages_id', $this->message->id);

        // Get all counts in one query for better performance
        $counts = $baseQuery->select([
            DB::raw('COUNT(CASE WHEN is_liked = 1 THEN 1 END) as total_liked'),
            DB::raw('COUNT(CASE WHEN is_saved = 1 THEN 1 END) as total_saved'),
            DB::raw('COUNT(CASE WHEN is_shared = 1 THEN 1 END) as total_shared'),
        ])->first();

        // Update the message with all counts
        $this->message->total_liked = $counts->total_liked;
        $this->message->total_saved = $counts->total_saved;
        $this->message->total_shared = $counts->total_shared;
        $this->message->saveOrFail();
    }
}
