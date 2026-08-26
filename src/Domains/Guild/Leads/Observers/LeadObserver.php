<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Actions\CreatePeopleByEmailAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Events\LeadCompanyUpdateEvent;
use Kanvas\Guild\Leads\Events\LeadUpdateEvent;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Agents\Neuron\RAG\Services\RagComponents;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Actions\WriteLeadStageChangeThreadMessageAction;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Events\KnowledgeIndexRequested;
use Kanvas\Intelligence\Sessions\Actions\DeleteSessionAction;
use Kanvas\Intelligence\Sessions\Actions\UpdateLeadSessionsAction;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Nuwave\Lighthouse\Execution\Utils\Subscription;

class LeadObserver
{
    public function creating(Lead $lead): void
    {
        $lead->phone = Str::sanitizePhoneNumber($lead->phone);

        // auto create new email
        if (empty($lead->people_id) && ! empty($lead->email)) {
            $lead->people_id = new CreatePeopleByEmailAction(
                $lead->email,
                $lead->user,
                $lead->company,
                $lead->firstname
            )->execute()->getId();
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
    }

    public function created(Lead $lead): void
    {
        if ($lead->people_id && $this->isCounted($lead)) {
            $this->adjustActiveLeadsCount((int) $lead->people_id, +1);
        }

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

        $this->queueKnowledgeIndex($lead);
        //$lead->clearLightHouseCacheJob();
    }

    public function updated(Lead $lead): void
    {
        $this->syncActiveLeadsCount($lead);

        //Subscription::broadcast('leadUpdate', $lead, true);
        LeadUpdateEvent::dispatch($lead);
        LeadCompanyUpdateEvent::dispatch($lead);

        if ($lead->company->get(ConfigurationEnum::AI_ENABLE->value)) {
            new UpdateLeadSessionsAction($lead)->execute();
        }
        if ($lead->wasChanged([
            'firstname',
            'lastname',
            'title',
            'email',
            'phone',
            'description',
            'people_id',
            'organization_id',
            'companies_id',
        ])) {
            $this->queueKnowledgeIndex($lead);
        }
        //$lead->clearLightHouseCacheJob();
    }

    public function deleted(Lead $lead): void
    {
        if ($lead->people_id && $this->isCounted($lead)) {
            $this->adjustActiveLeadsCount((int) $lead->people_id, -1);
        }

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

    private function queueKnowledgeIndex(Lead $lead): void
    {
        if (! RagComponents::isEnabled($lead)) {
            return;
        }

        KnowledgeIndexRequested::dispatch(KnowledgeEntity::fromModel($lead));
    }

    /**
     * Maintains the active_leads_count counter cache on People. "Counted"
     * mirrors Lead::isOpen() (status < 2) — not leads_status_id, which the
     * frontend and other Lead helpers (isActive(), close()/open()) define
     * inconsistently.
     */
    private function syncActiveLeadsCount(Lead $lead): void
    {
        $originalPeopleId = $lead->getOriginal('people_id') ? (int) $lead->getOriginal('people_id') : null;
        $currentPeopleId = $lead->people_id ? (int) $lead->people_id : null;
        $wasCounted = $this->wasCounted($lead);
        $isCounted = $this->isCounted($lead);

        if ($originalPeopleId === $currentPeopleId) {
            if ($wasCounted === $isCounted) {
                return;
            }

            if ($currentPeopleId !== null) {
                $this->adjustActiveLeadsCount($currentPeopleId, $isCounted ? +1 : -1);
            }

            return;
        }

        if ($wasCounted && $originalPeopleId !== null) {
            $this->adjustActiveLeadsCount($originalPeopleId, -1);
        }

        if ($isCounted && $currentPeopleId !== null) {
            $this->adjustActiveLeadsCount($currentPeopleId, +1);
        }
    }

    private function isCounted(Lead $lead): bool
    {
        return $lead->isOpen() && ! (bool) $lead->is_deleted;
    }

    private function wasCounted(Lead $lead): bool
    {
        return (int) ($lead->getOriginal('status') ?? 0) < 2
            && ! (bool) $lead->getOriginal('is_deleted');
    }

    private function adjustActiveLeadsCount(int $peopleId, int $delta): void
    {
        $query = People::where('id', $peopleId);

        if ($delta < 0) {
            $query->where('active_leads_count', '>', 0);
        }

        $query->increment('active_leads_count', $delta);
    }
}
