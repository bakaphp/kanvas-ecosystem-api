<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Every result that hands the model an invoice says when it is due, and says so loudly when nothing set terms: an
 * invoice due the day it is issued went to customers unnoticed because no tool result carried the date.
 */
trait PresentsInvoiceTerms
{
    public const string DUE_ON_RECEIPT_NOTE = 'Due on receipt: nothing set payment terms on this invoice, so its due '
        . 'date is its issue date. Tell the person. set_invoice_terms gives it a due date or net days; set_payment_terms '
        . 'with is_default makes a company default (e.g. Net 30) that new invoices, SOW schedules and billing schedules '
        . 'use when nothing else is given.';

    /**
     * @return array<string, mixed>
     */
    protected function invoiceTerms(Invoice $invoice): array
    {
        $net = (int) ($invoice->net_terms_days ?? 0);
        $due = $invoice->due_date;
        $issued = $invoice->issued_date ?? Carbon::today();
        $terms = [
            'due_date' => $due?->toDateString(),
            'net_terms_days' => $net,
        ];

        if ($net === 0 && ($due === null || $due->isSameDay($issued))) {
            $terms['terms_note'] = self::DUE_ON_RECEIPT_NOTE;
        }

        return $terms;
    }
}
