<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Events\LeadCompanyUpdateEvent;
use Kanvas\Guild\Leads\Events\LeadUpdateEvent;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Actions\WriteLeadStageChangeThreadMessageAction;
use Kanvas\Intelligence\Sessions\Actions\DeleteSessionAction;
use Kanvas\Intelligence\Sessions\Actions\UpdateLeadSessionsAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Messages\Actions\CreateMessageAction as CreateSocialMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Nuwave\Lighthouse\Execution\Utils\Subscription;
use Throwable;

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
            $lead->leads_status_id = LeadStatus::getDefault($lead->app, $lead->company)->getId();
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

    // Stage changes can originate from many call sites (UI, deal-won actions,
    // workflows, FollowUpLeadAction's auto-advance). Centralizing the reaction
    // here means every path triggers the same audit + state reset.
    public function updating(Lead $lead): void
    {
        if ($lead->isDirty('pipeline_stage_id')) {
            $fromStageId = $lead->getOriginal('pipeline_stage_id');
            $toStageId = $lead->pipeline_stage_id;

            $lead->resetFollowUpState();
            $lead->emitLedgerEvent('lead.stage.changed', payload: [
                'from_stage_id' => $fromStageId,
                'to_stage_id' => $toStageId,
            ]);

            // Social-side failures are swallowed by the action — Ledger event
            // above is the audit truth; never block the Lead update.
            new WriteLeadStageChangeThreadMessageAction(
                lead: $lead,
                fromStageId: $fromStageId !== null ? (int) $fromStageId : null,
                toStageId: (int) $toStageId,
            )->execute();
        }

        // DEBUG: writes a private, locked system note into the lead's system thread
        // every time leads_status_id changes, including a truncated PHP call stack so
        // we can trace which path (UI mutation, workflow, close-sold action, etc.) is
        // mutating the status. Remove once the source of unexpected status mutations
        // has been identified.
        if ($lead->isDirty('leads_status_id')) {
            $this->writeStatusChangeDebugNote(
                $lead,
                $lead->getOriginal('leads_status_id'),
                $lead->leads_status_id,
            );
        }
    }

    // DEBUG ONLY — see updating() for context. Best-effort; never blocks the Lead update.
    private function writeStatusChangeDebugNote(Lead $lead, mixed $fromStatusId, mixed $toStatusId): void
    {
        try {
            $channel = $lead->systemNotes;
            if ($channel === null) {
                return;
            }

            $user = $lead->company->getAiAgentUser() ?? $lead->user;
            if ($user === null) {
                return;
            }

            $fromName = $fromStatusId
                ? (LeadStatus::find($fromStatusId)?->name ?? "#{$fromStatusId}")
                : 'null';
            $toName = $toStatusId
                ? (LeadStatus::find($toStatusId)?->name ?? "#{$toStatusId}")
                : 'null';
            $tz = $lead->company->timezone ?? 'UTC';
            $when = Carbon::now($tz)->format('Y-m-d H:i:s');

            $trace = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15))
                ->skip(1)
                ->take(12)
                ->map(fn ($f) => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . ':' . ($f['line'] ?? '?'))
                ->implode("\n  ");

            $body = "[DEBUG] Status: {$fromName} → {$toName} · {$when}\nTrace:\n  {$trace}";

            $payload = new AiChatMessagePayload(
                content: $body,
                from_me: true,
                from_ia: true,
                raw_data: $body,
            );

            $messageInput = MessageInput::from([
                'app' => $lead->app,
                'company' => $lead->company,
                'user' => $user,
                'type' => MessageTypeService::getOrCreate($lead->app, 'system'),
                'message' => $payload->toArray(),
                'is_public' => 0,
            ]);

            $message = new CreateSocialMessageAction(
                $messageInput,
                SystemModulesRepository::getByModelName(Lead::class, $lead->app),
                $lead->getId(),
            )->execute();

            $channel->addMessage($message);
            $message->addTag('status-change-debug');
            $message->setLock();
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function created(Lead $lead): void
    {
        if ($lead->user) {
            (
                new CreateChannelAction(
                    new Channel(
                        $lead->app,
                        $lead->company,
                        $lead->user,
                        (string)$lead->getKey(),
                        Lead::class,
                        ChannelNameEnum::DEFAULT->value,
                        ! empty($lead->description) ? $lead->description : (string) $lead->uuid,
                        (string) $lead->uuid
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
                        ChannelNameEnum::NOTES->value,
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
        //Subscription::broadcast('leadUpdate', $lead, true);
        LeadUpdateEvent::dispatch($lead);
        LeadCompanyUpdateEvent::dispatch($lead);

        if ($lead->company->get(ConfigurationEnum::AI_ENABLE->value)) {
            new UpdateLeadSessionsAction($lead)->execute();
        }

        if ($lead->wasChanged('leads_status_id')) {
            if ($lead->closeSold()) {
                $lead->fireWorkflow(
                    WorkflowEnum::TRIGGER_AI->value,
                    true,
                    [
                        'trigger_type' => TriggersEnum::SOLD_LEAD->value,
                    ]
                );
            } elseif ($lead->closeNotSold()) {
                $lead->fireWorkflow(
                    WorkflowEnum::TRIGGER_AI->value,
                    true,
                    [
                        'trigger_type' => TriggersEnum::CLOSE_LEAD->value,
                    ]
                );
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
        //delete social channels related to this lead
        $lead->socialChannels()->update([
            'is_deleted' => 1,
            'updated_at' => now(),
        ]);

        new DeleteSessionAction($lead)->execute();
    }
}
