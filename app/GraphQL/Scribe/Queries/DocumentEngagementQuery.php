<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Queries;

use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;

class DocumentEngagementQuery
{
    /**
     * @return array<string, mixed>|null
     */
    public function page(Engagement $engagement): ?array
    {
        $reference = $engagement->hasNonLeadReference() ? $engagement->reference : null;

        return $reference instanceof Invoice || $reference instanceof Quote ? $reference->toTemplateData() : null;
    }
}
