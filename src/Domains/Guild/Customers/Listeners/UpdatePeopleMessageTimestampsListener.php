<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Listeners;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Events\AppModuleMessageCreatedEvent;

class UpdatePeopleMessageTimestampsListener implements ShouldQueue
{
    use KanvasJobsTrait;

    public function handle(AppModuleMessageCreatedEvent $event): void
    {
        $appModuleMessage = $event->appModuleMessage;

        if ((bool) $event->appModuleMessage->message?->isCommunicationMessage() === false) {
            return;
        }

        if ((bool) $event->appModuleMessage->message?->isPublic() === false) {
            return;
        }

        if ($appModuleMessage->system_modules !== Lead::class) {
            return;
        }

        $this->overwriteAppService($appModuleMessage->app);

        $messageAt = (string) $appModuleMessage->created_at;

        $affected = DB::connection('crm')->update(
            <<<'SQL'
            UPDATE peoples p
            INNER JOIN leads l ON l.people_id = p.id
            SET
                p.first_message_at = COALESCE(p.first_message_at, ?),
                p.last_message_at  = GREATEST(COALESCE(p.last_message_at, ?), ?)
            WHERE l.id = ?
              AND l.is_deleted = 0
              AND p.is_deleted = 0
            SQL,
            [$messageAt, $messageAt, $messageAt, $appModuleMessage->entity_id],
        );

        if ($affected === 0) {
            return;
        }

        $appModuleMessage->entity?->people?->set('unread_message', 1);
    }
}
