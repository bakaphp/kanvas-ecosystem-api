<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

enum CorporateApplicationStatusEnum: string
{
    case PENDING = 'pending';

    /**
     * Predates manual approval — set when auto-approve is on and validation fails. The
     * admin queue has to include it alongside PENDING or those applications go invisible.
     */
    case NEEDS_REVIEW = 'needs_review';

    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
