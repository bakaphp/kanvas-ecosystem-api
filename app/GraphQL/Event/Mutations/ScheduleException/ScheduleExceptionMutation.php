<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\ScheduleException;

use App\GraphQL\Event\Traits\ResolvesScheduleResource;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\ScheduleException;
use Kanvas\Exceptions\ValidationException;

class ScheduleExceptionMutation
{
    use ResolvesScheduleResource;

    public function create(mixed $root, array $req): ScheduleException
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $input = $req['input'];
        $entity = $this->getResource($input['resources_type'], $input['resources_id']);

        $overlap = ScheduleException::where('resources_id', $entity->getId())
            ->where('resources_type', $entity->getMorphClass())
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->where(function ($query) use ($input) {
                $query->where('window_start', '<', $input['window_end'])
                      ->where('window_end', '>', $input['window_start']);
            })
            ->exists();

        if ($overlap) {
            throw new ValidationException('Exception overlaps with an existing exception for this resource');
        }

        return ScheduleException::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'resources_id' => $entity->getId(),
            'resources_type' => $entity->getMorphClass(),
            'window_start' => $input['window_start'],
            'window_end' => $input['window_end'],
            'kind' => strtolower($input['kind']),
            'reason' => $input['reason'] ?? null,
        ]);
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $exception = ScheduleException::getByIdFromCompanyApp((int) $req['id'], $user->getCurrentCompany(), $app);

        return $exception->delete();
    }
}
