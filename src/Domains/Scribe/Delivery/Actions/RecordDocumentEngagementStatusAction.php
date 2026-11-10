<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Actions;

use InvalidArgumentException;
use Kanvas\ActionEngine\Engagements\Actions\ContinueEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Users\Models\Users;

/**
 * Moves a document engagement thread to a status the way the lead pages do: a new engagement row in
 * the same thread (`entity_uuid`), so `stage_history` keeps every step with its time. A status the
 * thread already reached is not recorded twice (the page reports `opened` on every visit).
 *
 * The row is attributed to the user who shared the document, never to the page's app-key user, the
 * same rule `continueLeadEngagement` follows.
 */
class RecordDocumentEngagementStatusAction
{
    public function __construct(
        private readonly Engagement $thread,
        private readonly ActionStatusEnum $status,
        private readonly array $data = [],
    ) {
    }

    public function execute(): Engagement
    {
        $document = self::documentOf($this->thread);

        $existing = $this->rowWithStatus();
        if ($existing !== null) {
            return $existing;
        }

        /** @var Users $user */
        $user = $this->thread->user;

        $engagement = new ContinueEngagementAction(
            EngagementData::from(
                $user,
                $this->thread->people,
                $document,
                [
                    'action' => $this->thread->slug,
                    'request_id' => $this->thread->entity_uuid,
                    'source' => 'scribe',
                    'status' => $this->status->value,
                    'data' => $this->data,
                ]
            )
        )->execute();

        if ($this->status === ActionStatusEnum::OPEN && $document->delivery_status === 'sent') {
            $document->delivery_status = 'opened';
            $document->saveQuietly();
        }

        $document->emitLedgerEvent(
            'scribe.' . $document->documentKind() . '.engagement.' . $this->status->value,
            payload: [
                'engagement_uuid' => $engagement->uuid,
                'thread' => $this->thread->entity_uuid,
                'action' => $this->thread->slug,
                'people_id' => $this->thread->people_id,
            ]
        );

        return $engagement;
    }

    public static function documentOf(Engagement $engagement): Invoice|Quote
    {
        $document = $engagement->reference;

        if (! $document instanceof Invoice && ! $document instanceof Quote) {
            throw new InvalidArgumentException('This engagement does not share a Scribe document.');
        }

        if (DocumentActionEnum::tryFrom((string) $engagement->slug) === null) {
            throw new InvalidArgumentException("'{$engagement->slug}' is not a Scribe document action.");
        }

        return $document;
    }

    private function rowWithStatus(): ?Engagement
    {
        /** @var Engagement|null $row */
        $row = Engagement::query()
            ->inThreadOf($this->thread)
            ->where('slug', $this->thread->slug)
            ->atStage($this->status)
            ->first();

        return $row;
    }
}
