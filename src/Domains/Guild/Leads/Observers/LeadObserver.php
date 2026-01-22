<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Events\LeadUpdateEvent;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Sessions\Actions\DeleteSessionAction;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Nuwave\Lighthouse\Execution\Utils\Subscription;

class LeadObserver
{
    public function creating(Lead $lead): void
    {
        $lead->phone = Str::sanitizePhoneNumber($lead->phone);

        // auto create new email
        if (empty($lead->people_id) && ! empty($lead->email)) {
            $lead->people_id = PeoplesRepository::findByEmailOrCreate(
                $lead->email,
                $lead->user,
                $lead->company,
                $lead->firstname
            )->getId();
        }

        if (empty($lead->title)) {
            $lead->title = $lead->firstname . ' ' . $lead->lastname;
        }

        // set the default status if not specified
        if (! $lead->leads_status_id) {
            $lead->leads_status_id = LeadStatus::getDefault($lead->app)->getId();
        }

        // if no pipeline assign one
        if (! $lead->pipeline_id) {
            $pipeline = Pipeline::where('companies_id', $lead->companies_id)
                ->where('is_deleted', 0)
                ->where('apps_id', $lead->apps_id)
                ->orderBy('is_default', 'desc')
                ->first();

            if ($pipeline) {
                $lead->pipeline_id = $pipeline->id;
                $lead->pipeline_stage_id = $pipeline->stages->first()->id;
            }
        }

        if (! $lead->leads_receivers_id) {
            $receiver = LeadReceiver::where('companies_id', $lead->companies_id)
                ->where('is_deleted', 0)
                ->orderBy('is_default', 'desc')
                ->first();

            $lead->leads_receivers_id = $receiver ? $receiver->id : 0;
        }
    }

    public function created(Lead $lead): void
    {
        //$lead->fireWorkflow(WorkflowEnum::CREATED->value);
        if ($lead->user) {
            (
                new CreateChannelAction(
                    new Channel(
                        $lead->app,
                        $lead->company,
                        $lead->user,
                        (string)$lead->getKey(),
                        Lead::class,
                        'Default Channel',
                        ! empty($lead->description) ? $lead->description : $lead->uuid->toString(),
                        $lead->uuid->toString()
                    )
                )
            )->execute();

            $aiNotesChannel = $lead->company->get('enable_ai_notes_channel', false);

            if ($aiNotesChannel) {
                $channel = new CreateChannelAction(
                    new Channel(
                        $lead->app,
                        $lead->company,
                        $lead->user,
                        (string)$lead->getKey(),
                        Lead::class,
                        'Notes',
                        'AI Notes Channel',
                        Str::uuid()->toString()
                    )
                )
                ->execute();

                $channel->addCategory(
                    'ai-agent',
                    $lead->app,
                    $lead->user,
                    $lead->company
                );
            }
        }

        //$lead->clearLightHouseCacheJob();
    }

    public function updated(Lead $lead): void
    {
        //$lead->fireWorkflow(WorkflowEnum::UPDATED->value);
        //Subscription::broadcast('leadUpdate', $lead, true);
        LeadUpdateEvent::dispatch($lead);
        if ($lead->wasChanged('leads_status_id')) {
            if (strtolower($lead->status->name) === 'sold') {
                $lead->fireWorkflow(WorkflowEnum::TRIGGER_IA->value, true, [
                    'trigger_type' => TriggersEnum::SOLD_LEAD->value,
                ]);
            } elseif (strtolower($lead->status->name) === 'close') {
                $lead->fireWorkflow(WorkflowEnum::TRIGGER_IA->value, true, [
                    'trigger_type' => TriggersEnum::CLOSE_LEAD->value,
                ]);
            }
        }
        //$lead->clearLightHouseCacheJob();
    }

    public function deleted(Lead $lead): void
    {
        //delete social channel related to this lead
        $channel = $lead->getSocialChannel();

        if ($channel) {
            $channel->delete();
        }
        new DeleteSessionAction($lead)->execute();
    }

    public function softDeleted(Lead $lead): void
    {
        //delete social channel related to this lead
        $channels = $lead->socialChannels;
        foreach ($channels as $channel) {
            $channel->delete();
        }
        new DeleteSessionAction($lead)->execute();
    }
}
