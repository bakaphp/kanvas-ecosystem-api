<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Actions;

use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Override;

/**
 * `generate-id-verification` files every scan as its own engagement: a reused one piles each rescan's PDF
 * into one message, and on legacy data a person's engagement can share its message with someone else's.
 * Overriding here, not flagging the shared action, keeps `reuseExistingEngagement` (and with it the
 * never-read `driver_license_images` mailbox) and creates the engagement where the parent does — after the
 * PDF and the dedup window — so a failed or deduped run leaves no empty engagement behind.
 */
class VerifyPeopleIdInNewEngagementAction extends VerifyPeopleIdAction
{
    #[Override]
    public function resolveEngagement(?Engagement $parentEngagement = null, bool $reuseExistingEngagement = false): ?Engagement
    {
        return $this->createEngagement($parentEngagement);
    }
}
