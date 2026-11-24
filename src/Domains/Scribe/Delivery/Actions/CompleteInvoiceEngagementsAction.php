<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Actions;

use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Throwable;

/**
 * Paid closes every `view-invoice` thread the invoice was shared in (plan §5.2: "paid, from Scribe
 * when the payment is applied"). Runs after the payment commits; a failure here is reported and never
 * reaches the payment, which is already booked.
 */
class CompleteInvoiceEngagementsAction
{
    public function __construct(
        private readonly Invoice $invoice,
    ) {
    }

    /**
     * @return int threads completed
     */
    public function execute(): int
    {
        if (! $this->invoice->isPaid()) {
            return 0;
        }

        $completed = 0;
        foreach ($this->threads() as $thread) {
            try {
                new RecordDocumentEngagementStatusAction(
                    $thread,
                    ActionStatusEnum::COMPLETED,
                    ['paid_at' => $this->invoice->paid_at?->toIso8601String()]
                )->execute();
                $completed++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $completed;
    }

    /**
     * @return list<Engagement>
     */
    private function threads(): array
    {
        return Engagement::query()
            ->sharing($this->invoice, DocumentActionEnum::VIEW_INVOICE->value)
            ->orderBy('id')
            ->get()
            ->unique('entity_uuid')
            ->values()
            ->all();
    }
}
