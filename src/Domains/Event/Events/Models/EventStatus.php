<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Kanvas\Event\Models\BaseModel;

class EventStatus extends BaseModel
{
    protected $table = 'event_statuses';
    protected $guarded = [];

    protected $casts = [
        'valid_transitions' => 'array',
        'transition_validations' => 'array',
    ];

    /**
     * Check if transition to another status is valid
     */
    public function canTransitionTo(EventStatus $targetStatus): bool
    {
        if (! $this->valid_transitions) {
            return true; // No restrictions defined
        }

        // Check by status ID or name
        return in_array($targetStatus->id, $this->valid_transitions) ||
               in_array($targetStatus->name, $this->valid_transitions);
    }

    /**
     * Get validation requirements for transitioning TO this status
     */
    public function getTransitionValidations(): ?array
    {
        return $this->transition_validations;
    }
}
