<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Facilitators;

use App\GraphQL\Concerns\SyncsEntityRelatedInput;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Facilitators\Models\EventVersionFacilitator;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Guild\Customers\Models\People;

class FacilitatorMutation
{
    use SyncsEntityRelatedInput;

    public function create(mixed $root, array $req): Facilitator
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var People $people */
        $people = People::getByIdFromCompanyApp((int) $input['people_id'], $company, $app);

        $facilitator = new Facilitator();
        $facilitator->apps_id = $app->getId();
        $facilitator->companies_id = $company->getId();
        $facilitator->users_id = $user->getId();
        $facilitator->people_id = $people->getId();
        $facilitator->slug = Str::slug($people->firstname . ' ' . $people->lastname . '-' . uniqid());
        $facilitator->identification = $input['identification'] ?? null;
        $facilitator->resume = $input['resume'] ?? null;
        $facilitator->description = $input['description'] ?? null;
        $facilitator->saveOrFail();

        self::syncEntityRelatedInput($facilitator, $input);

        return $facilitator->fresh();
    }

    public function update(mixed $root, array $req): Facilitator
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var Facilitator $facilitator */
        $facilitator = Facilitator::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        if (array_key_exists('identification', $input)) {
            $facilitator->identification = $input['identification'];
        }
        if (array_key_exists('resume', $input)) {
            $facilitator->resume = $input['resume'];
        }
        if (array_key_exists('description', $input)) {
            $facilitator->description = $input['description'];
        }
        if (array_key_exists('people_id', $input) && $input['people_id']) {
            $people = People::getByIdFromCompanyApp((int) $input['people_id'], $company, $app);
            $facilitator->people_id = $people->getId();
        }

        $facilitator->saveOrFail();

        self::syncEntityRelatedInput($facilitator, $input);

        return $facilitator->fresh();
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var Facilitator $facilitator */
        $facilitator = Facilitator::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        return (bool) $facilitator->delete();
    }

    public function attachToEventVersion(mixed $root, array $req): EventVersionFacilitator
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var EventVersion $eventVersion */
        $eventVersion = EventVersion::getByIdFromCompanyApp((int) $input['event_version_id'], $company, $app);
        /** @var Facilitator $facilitator */
        $facilitator = Facilitator::getByIdFromCompanyApp((int) $input['facilitator_id'], $company, $app);

        $pivot = EventVersionFacilitator::withTrashed()
            ->where('event_version_id', $eventVersion->getId())
            ->where('facilitator_id', $facilitator->getId())
            ->first();

        if ($pivot) {
            $pivot->restore();
            $pivot->is_deleted = 0;
            $pivot->saveOrFail();

            return $pivot;
        }

        $pivot = new EventVersionFacilitator();
        $pivot->event_version_id = $eventVersion->getId();
        $pivot->facilitator_id = $facilitator->getId();
        $pivot->is_deleted = 0;
        $pivot->saveOrFail();

        return $pivot;
    }

    public function detachFromEventVersion(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        $eventVersion = EventVersion::getByIdFromCompanyApp((int) $input['event_version_id'], $company, $app);
        $facilitator = Facilitator::getByIdFromCompanyApp((int) $input['facilitator_id'], $company, $app);

        $pivot = EventVersionFacilitator::where('event_version_id', $eventVersion->getId())
            ->where('facilitator_id', $facilitator->getId())
            ->first();

        if (! $pivot) {
            return false;
        }

        return (bool) $pivot->delete();
    }
}
