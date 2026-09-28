<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

/**
 * "Stage: X → Y" system message into the lead's DEFAULT Social thread.
 * Best-effort — all paths swallow + report; failure must never block the
 * underlying Lead update. The Ledger event is the audit truth.
 */
final class WriteLeadStageChangeThreadMessageAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly ?int $fromStageId,
        protected readonly int $toStageId,
    ) {
    }

    public function execute(): ?Message
    {
        return new RecordLeadNoteAction($this->lead)
            ->execute(
                body: $this->renderBody(),
                tag: 'stage-change',
                isPublic: false
            );
    }

    private function renderBody(): string
    {
        $fromName = $this->stageName($this->fromStageId) ?? 'start';
        $toName = $this->stageName($this->toStageId) ?? '(unknown)';
        $tz = $this->lead->company->timezone ?? 'UTC';
        $when = Carbon::now($tz)->format('Y-m-d H:i');

        return "Stage: {$fromName} → {$toName} · {$when}";
    }

    private function stageName(?int $stageId): ?string
    {
        if ($stageId === null) {
            return null;
        }

        try {
            $stage = PipelineStage::getById($stageId);

            return $stage?->name;
        } catch (Throwable) {
            return null;
        }
    }
}
