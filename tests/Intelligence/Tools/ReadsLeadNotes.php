<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;

trait ReadsLeadNotes
{
    protected function latestLeadNote(Lead $lead): ?Message
    {
        $channel = $lead->systemNotes ?? $lead->notes;

        return $channel?->messages()->latest('messages.id')->first();
    }

    protected function latestLeadNoteContent(Lead $lead): string
    {
        return (string) ($this->latestLeadNote($lead)?->message['content'] ?? '');
    }
}
