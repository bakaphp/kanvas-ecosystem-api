<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Contracts;

/**
 * Lets a single-lead manual trigger bypass the "minutes since last message"
 * gate without mutating the stored message history (which every other read —
 * agent context, dashboards, the WhatsApp 24h window — depends on).
 * @deprecated going to be removed on v2
 */
interface FollowUpTimeGateOverridable
{
    public function withIgnoreTimeGate(bool $ignore = true): static;
}
