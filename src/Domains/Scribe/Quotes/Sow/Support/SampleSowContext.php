<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Sow\Support;

/**
 * The shape QuoteSowPdfService::viewData() hands a SOW template, with sample values. It checks a layout when the
 * company has no SOW yet, and shows the agent every variable it can use.
 */
final class SampleSowContext
{
    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return [
            'title' => 'Statement of Work',
            'revision' => 1,
            'number' => 'Q-0001',
            'status' => 'sent',
            'currency' => 'USD',
            'issuer' => [
                'name' => 'Your Company',
                'logo' => null,
                'address_lines' => ['100 Main St', 'Santo Domingo'],
                'email' => 'billing@example.com',
                'phone' => '+1 809 555 0100',
                'website' => 'example.com',
            ],
            'customer' => [
                'name' => 'Customer Inc',
                'legal_name' => 'Customer Inc SRL',
                'tax_id' => '101000001',
                'email' => 'ap@customer.example',
                'address_lines' => ['200 Second Ave'],
                'attention' => 'Jane Customer',
            ],
            'issued_date' => 'Jun 1, 2026',
            'expiry_label' => 'Valid until',
            'expiry_date' => 'Jun 30, 2026',
            'lines' => [
                ['description' => 'Discovery workshop', 'sku' => null, 'quantity' => 1, 'unit_price' => 1000, 'discount' => 0, 'tax' => 0, 'total' => 1000],
                ['description' => 'App build', 'sku' => null, 'quantity' => 1, 'unit_price' => 3000, 'discount' => 0, 'tax' => 0, 'total' => 3000],
            ],
            'totals' => ['subtotal' => 4000, 'discount' => 0, 'tax' => 0, 'total' => 4000, 'paid' => null, 'balance_due' => null],
            'notes' => null,
            'terms' => 'Net 30',
            'sow' => [
                'quote_id' => 1,
                'summary' => 'Build and publish the app.',
                'scope' => ['Discovery workshop', 'App build'],
                'out_of_scope' => ['Hosting'],
                'assumptions' => ['Customer provides content'],
                'deliverables' => [['name' => 'The app', 'acceptance_criteria' => 'Published in the store.', 'quote_line_ids' => [1, 2]]],
                'timeline' => ['start' => 'Jun 1, 2026', 'end' => 'Jun 30, 2026'],
                'payment_schedule' => [],
                'change_process' => 'Changes are quoted as a change order.',
                'payment_terms_id' => null,
                'signatories' => [['name' => 'Jane Customer', 'role' => 'CEO']],
                'template' => null,
            ],
            'payment_rows' => [
                ['label' => 'Deposit', 'due' => 'On signature', 'percent' => 50, 'amount' => '2,000.00'],
                ['label' => 'Balance', 'due' => 'Jun 20, 2026', 'percent' => 50, 'amount' => '2,000.00'],
            ],
            'quote_total' => '4,000.00',
        ];
    }
}
