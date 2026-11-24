<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Kanvas\Scribe\PaymentTerms\Models\PaymentTerm;

trait PresentsPaymentTerms
{
    /**
     * @return array<string, mixed>
     */
    protected function presentPaymentTerm(PaymentTerm $term): array
    {
        return [
            'payment_term_id' => $term->getId(),
            'name' => $term->name,
            'net_days' => (int) $term->net_days,
            'discount_days' => $term->discount_days,
            'discount_pct' => $term->discount_pct,
            'is_default' => (bool) $term->is_default,
        ];
    }
}
