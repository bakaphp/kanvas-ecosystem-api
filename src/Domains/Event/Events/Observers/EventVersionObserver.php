<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Observers;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;

class EventVersionObserver
{
    public function created(EventVersion $eventVersion): void
    {
        $this->syncEventCounters($eventVersion);
        $this->createChannels($eventVersion);
    }

    public function updating(EventVersion $eventVersion): void
    {
        $eventVersion->clearLightHouseCache(withKanvasConfiguration: false);
    }

    public function updated(EventVersion $eventVersion): void
    {
        if ($eventVersion->wasChanged(['start_at', 'end_at'])) {
            $this->syncEventCounters($eventVersion);
        }
    }

    public function deleted(EventVersion $eventVersion): void
    {
        $this->syncEventCounters($eventVersion);

        foreach ($eventVersion->socialChannels as $channel) {
            $channel->delete();
        }

        Session::where('entity_namespace', EventVersion::class)
            ->where('entity_id', (string) $eventVersion->getKey())
            ->get()
            ->each(fn (Session $session) => $session->delete());
    }

    protected function createChannels(EventVersion $eventVersion): void
    {
        if (! $eventVersion->user) {
            return;
        }

        new CreateChannelAction(
            new Channel(
                $eventVersion->app,
                $eventVersion->company,
                $eventVersion->user,
                (string) $eventVersion->getKey(),
                EventVersion::class,
                ChannelNameEnum::DEFAULT->value,
                ! empty($eventVersion->description) ? $eventVersion->description : (string) $eventVersion->uuid,
                (string) $eventVersion->uuid,
            ),
        )->execute();

        if ($eventVersion->company->get('enable_ai_notes_channel', false)) {
            $notesChannel = new CreateChannelAction(
                new Channel(
                    $eventVersion->app,
                    $eventVersion->company,
                    $eventVersion->user,
                    (string) $eventVersion->getKey(),
                    EventVersion::class,
                    ChannelNameEnum::NOTES->value,
                    'AI Notes Channel',
                    Str::uuid()->toString(),
                ),
            )->execute();

            $notesChannel->addCategory(
                'ai-agent',
                $eventVersion->app,
                $eventVersion->user,
                $eventVersion->company,
            );
        }
    }

    /**
     * Keep the parent Event's versions_count, start_at, and end_at in sync
     * with its EventVersions. start_at = earliest version; end_at = latest version.
     */
    protected function syncEventCounters(EventVersion $eventVersion): void
    {
        /** @var Event|null $event */
        $event = $eventVersion->event;
        if ($event === null) {
            return;
        }

        $aggregates = DB::connection('event')
            ->table('event_versions')
            ->where('event_id', $event->getId())
            ->where('is_deleted', 0)
            ->selectRaw('COUNT(*) as cnt, MIN(start_at) as min_start, MAX(end_at) as max_end')
            ->first();

        $event->versions_count = $aggregates !== null ? (int) $aggregates->cnt : 0;

        if ($aggregates !== null && $aggregates->min_start !== null) {
            $event->start_at = $aggregates->min_start;
        }

        if ($aggregates !== null && $aggregates->max_end !== null) {
            $event->end_at = $aggregates->max_end;
        }

        $event->saveQuietly();
    }
}
