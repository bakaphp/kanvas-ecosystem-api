<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Enums;

enum ApprovalConfigurationEnum: string
{
    /**
     * App setting: where an approver reviews pending requests. The approval emails show a review
     * button only when it is set.
     */
    case REVIEW_URL = 'approvals_review_url';
}
