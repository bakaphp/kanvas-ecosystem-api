<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Events;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionDate;

class EventVersionDateMutation
{
    public function add(mixed $root, array $req): EventVersionDate
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var EventVersion $eventVersion */
        $eventVersion = EventVersion::getByIdFromCompanyApp(
            (int) $input['event_version_id'],
            $company,
            $app,
        );

        $date = new EventVersionDate();
        $date->event_version_id = $eventVersion->getId();
        $date->users_id = $user->getId();
        $date->event_date = $input['date'];
        $date->start_time = $input['start_time'] ?? null;
        $date->end_time = $input['end_time'] ?? null;
        $date->saveOrFail();

        return $date;
    }

    public function update(mixed $root, array $req): EventVersionDate
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var EventVersionDate $date */
        $date = EventVersionDate::where('id', (int) $req['id'])->firstOrFail();

        EventVersion::getByIdFromCompanyApp(
            (int) $date->event_version_id,
            $company,
            $app,
        );

        if (array_key_exists('date', $input)) {
            $date->event_date = $input['date'];
        }
        if (array_key_exists('start_time', $input)) {
            $date->start_time = $input['start_time'];
        }
        if (array_key_exists('end_time', $input)) {
            $date->end_time = $input['end_time'];
        }
        $date->saveOrFail();

        return $date;
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var EventVersionDate $date */
        $date = EventVersionDate::where('id', (int) $req['id'])->firstOrFail();

        EventVersion::getByIdFromCompanyApp(
            (int) $date->event_version_id,
            $company,
            $app,
        );

        return (bool) $date->delete();
    }
}
