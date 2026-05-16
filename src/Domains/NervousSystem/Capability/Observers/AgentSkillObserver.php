<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Observers;

use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\Skill;

/**
 * Maintains the agents_using_count counter cache on Skill. Counts grants
 * that satisfy is_active=1 AND is_deleted=0. Expiration via expires_at is
 * not tracked here directly — ExpireCapabilitiesAction flips is_active to
 * false on expiry, which trips the updated() handler below.
 */
class AgentSkillObserver
{
    public function created(AgentSkill $grant): void
    {
        if ($this->isCounted($grant)) {
            $this->adjust($grant->skill_id, +1);
        }
    }

    public function updated(AgentSkill $grant): void
    {
        $wasCounted = (bool) $grant->getOriginal('is_active')
            && ! (bool) $grant->getOriginal('is_deleted');
        $isCounted = $this->isCounted($grant);

        if ($wasCounted === $isCounted) {
            return;
        }

        $this->adjust($grant->skill_id, $isCounted ? +1 : -1);
    }

    public function deleted(AgentSkill $grant): void
    {
        // Hard delete path — soft delete (is_deleted=1) goes through updated().
        $wasCounted = (bool) $grant->getOriginal('is_active')
            && ! (bool) $grant->getOriginal('is_deleted');

        if ($wasCounted) {
            $this->adjust($grant->skill_id, -1);
        }
    }

    private function isCounted(AgentSkill $grant): bool
    {
        return (bool) $grant->is_active && ! (bool) $grant->is_deleted;
    }

    private function adjust(int $skillId, int $delta): void
    {
        Skill::where('id', $skillId)->increment('agents_using_count', $delta);
    }
}
