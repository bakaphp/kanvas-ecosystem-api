<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;

class ReminderEventVersionCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:agent:events-versions-reminder {agent_id}';

    public function handle(): void
    {
        $eventVersions = EventVersion::whereHas('dates', function (Builder $builder) {
            $builder->where('event_date', '=', now()->toDateString());
        })
        ->get();
        foreach ($eventVersions as $eventVersion) {
            $name = $eventVersion->event->name;
            $starDate = $eventVersion->dates->first()->event_date;
            $content = [
                'origin' => 'trigger',
                'type' => 'kanvas_reminder',
                'created_by' => 'kanvas',
                'content' => [
                    'start_date' => $starDate,
                    'title' => $name,
                    'invite' => $this->getContacts($eventVersion),
                    'google_meet'=> true,
                    'llm_message' => "[TRIGGER] please send a reminder to the people for this event called $name at $starDate"
                ],
            ];
            $dto = Session::from([
                'app' => $eventVersion->app,
                'company' => $eventVersion->company,
                'agent' => Agent::getById($this->argument('agent_id')),
                'entity_namespace' => get_class($eventVersion->app),
                'entity_id' => $eventVersion->app->getId(),
                'session_id' => 'app_' . $eventVersion->app->getId(),
                'content' => $content,
            ]);
            
        }
    }

    protected function getContacts(EventVersion $eventVersion): array
    {
        $emails = [];
        foreach ($eventVersion->participants as $participant) {
            $email = array_merge($emails, $participant->people->emails->pluck('values'));
        }

        return $emails;
    }
}
