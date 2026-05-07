<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Schedule;

use App\GraphQL\Event\Traits\ResolvesScheduleResource;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\BuildScheduleResponseAction;
use Kanvas\Event\Events\Actions\ResourceScheduleValidator;

class ResourceScheduleQuery
{
    use ResolvesScheduleResource;

    public function get(mixed $root, array $req): array
    {
        $app = app(Apps::class);
        $resource = $this->getResource($req['resources_type'], $req['resources_id']);

        return new BuildScheduleResponseAction($resource, $app)->execute();
    }

    public function isOpen(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $resource = $this->getResource($req['resources_type'], $req['resources_id']);
        $datetime = isset($req['datetime']) ? Carbon::parse($req['datetime']) : null;

        return ResourceScheduleValidator::isResourceOpen($resource, $datetime, $app);
    }
}
