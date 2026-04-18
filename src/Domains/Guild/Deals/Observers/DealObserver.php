<?php

declare(strict_types=1);

namespace Kanvas\Guild\Deals\Observers;

use Baka\Support\Str;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;

class DealObserver
{
    public function creating(Deal $deal): void
    {
        if ($deal->title === null || $deal->title === '') {
            $deal->title = 'Deal ' . ($deal->uuid ?? Str::uuid()->toString());
        }

        if (! $deal->status_id) {
            $deal->status_id = LeadStatus::getDefault($deal->app)->getId();
        }

        if (! $deal->pipeline_id) {
            $pipeline = Pipeline::where('companies_id', $deal->companies_id)
                ->where('is_deleted', 0)
                ->where('apps_id', $deal->apps_id)
                ->orderBy('is_default', 'desc')
                ->first();

            if ($pipeline) {
                $deal->pipeline_id = $pipeline->id;
                $deal->pipeline_stage_id = $pipeline->stages->first()?->id ?? 0;
            }
        }
    }

    public function created(Deal $deal): void
    {
        if (! $deal->user) {
            return;
        }

        $description = $deal->description;
        $channelDescription = ($description === null || $description === '')
            ? $deal->uuid
            : $description;

        new CreateChannelAction(
            new ChannelData(
                $deal->app,
                $deal->company,
                $deal->user,
                (string) $deal->getKey(),
                Deal::class,
                ChannelNameEnum::DEFAULT->value,
                $channelDescription,
                $deal->uuid
            )
        )->execute();

        if ($deal->company->get('enable_ai_notes_channel', false)) {
            $channel = new CreateChannelAction(
                new ChannelData(
                    $deal->app,
                    $deal->company,
                    $deal->user,
                    (string) $deal->getKey(),
                    Deal::class,
                    ChannelNameEnum::NOTES->value,
                    'AI Notes Channel',
                    Str::uuid()->toString()
                )
            )->execute();

            $channel->addCategory(
                'ai-agent',
                $deal->app,
                $deal->user,
                $deal->company
            );
        }
    }

    public function deleted(Deal $deal): void
    {
        $this->cleanupRelatedChannelsAndSessions($deal);
    }

    public function softDeleted(Deal $deal): void
    {
        $this->cleanupRelatedChannelsAndSessions($deal);
    }

    protected function cleanupRelatedChannelsAndSessions(Deal $deal): void
    {
        foreach ($deal->socialChannels as $channel) {
            $channel->delete();
        }

        Session::where('entity_namespace', Deal::class)
            ->where('entity_id', $deal->getId())
            ->get()
            ->each(fn (Session $session) => $session->delete());
    }
}
