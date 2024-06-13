<?php

declare(strict_types=1);

namespace Baka\Traits;

use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Searchable;

trait DatabaseSearchableTrait
{
    use Searchable {
        search as public traitSearch;
    }

    /**
     * Get the engine used to index the model.
     */
    public function searchableUsing(): Engine
    {
        return app(EngineManager::class)->engine('database');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
