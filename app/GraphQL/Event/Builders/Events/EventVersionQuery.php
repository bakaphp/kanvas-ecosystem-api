<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Builders\Events;

use Baka\Enums\StateEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;

class EventVersionQuery
{
    public function getEventVersion(mixed $root, array $args): mixed
    {
        $app = app(Apps::class);

        print_r($root->toArray()); die();
        return EventVersion::fromApp($app)
                ->where('event_id', $root->id)
                ->where('is_deleted', StateEnums::NO->getValue());
    }
}
