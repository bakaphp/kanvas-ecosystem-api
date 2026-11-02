<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Contracts\ReadableAtLocation;
use Kanvas\Companies\Support\LocationContext;
use Kanvas\Event\Models\BaseModel;
use Override;

class EventClass extends BaseModel implements ReadableAtLocation
{
    use DatabaseSearchableTrait;

    protected $table = 'event_classes';
    protected $guarded = [];

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_event_class_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'event_class_index');
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

        $locationCompany = $user instanceof UserInterface ? LocationContext::readableCompany(static::class, $user) : null;

        if ($locationCompany !== null) {
            $query->where('companies_id', $locationCompany->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
