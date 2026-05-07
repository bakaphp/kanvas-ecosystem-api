<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Event\Models\BaseModel;
use Override;

class EventStatus extends BaseModel
{
    use DatabaseSearchableTrait;

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

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_event_status_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'event_status_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
