<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
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

        // set the default status if not specified
        if (! $lead->leads_status_id) {
            $lead->leads_status_id = LeadStatus::getDefault()->getId();
        }

        // if no pipeline assign one
        if (! $lead->pipeline_id) {
            $pipeline = Pipeline::where('companies_id', $lead->companies_id)
                ->where('is_deleted', 0)
                ->first();

            if ($pipeline) {
                $lead->pipeline_id = $pipeline->id;
                $lead->pipeline_stage_id = $pipeline->stages->first()->id;
            }
        }

        if (! $lead->leads_receivers_id) {
            $receiver = LeadReceiver::where('companies_id', $lead->companies_id)
                ->where('is_default', 1)
                ->where('is_deleted', 0)
                ->first();

            if (! $receiver) {
                $receiver = LeadReceiver::where('companies_id', $lead->companies_id)
                    ->where('is_deleted', 0)
                    ->first();
            }

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
                        app(Apps::class),
                        $lead->company,
                        $lead->user,
                        (string)$lead->id,
                        Lead::class,
                        'Default Channel',
                        $lead->description ?? '',
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
        Subscription::broadcast('leadUpdate', $lead, true);
        //$lead->clearLightHouseCacheJob();
    }
}
