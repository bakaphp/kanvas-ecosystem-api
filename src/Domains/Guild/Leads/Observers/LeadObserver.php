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
        }

        //$lead->clearLightHouseCacheJob();
    }

    public function updated(Lead $lead): void
    {
        //$lead->fireWorkflow(WorkflowEnum::UPDATED->value);
        //Subscription::broadcast('leadUpdate', $lead, true);
        LeadUpdateEvent::dispatch($lead);

        //$lead->clearLightHouseCacheJob();
    }
}
