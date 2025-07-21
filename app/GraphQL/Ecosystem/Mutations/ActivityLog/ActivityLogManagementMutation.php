<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\ActivityLog;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\SystemModules\Models\SystemModules;
use Spatie\Activitylog\Models\Activity;

class ActivityLogManagementMutation
{
    public function createActivityLog(mixed $root, array $req): Activity
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $systemModules = SystemModules::getByUuid($req['input']['system_module_uuid'], $app);
        $module = $systemModules->model_name;

        $entity = $module::getById($req['input']['entity_id'], $app);
        $logName = $req['input']['log_name'] ?? 'default';

        // Get the previous activity with the same log_name for this entity
        $previousActivity = Activity::where('subject_type', $module)
            ->where('subject_id', $entity->getId())
            ->where('log_name', $logName)
            ->latest()
            ->first();

        // Get existing properties from previous activity
        $existingProperties = $previousActivity && $previousActivity->properties->isNotEmpty()
            ? $previousActivity->properties->toArray()
            : [];

        // Get new properties from the request
        $newProperties = (array) $req['input']['properties'];

        // Merge properties - new properties take priority, but preserve existing ones
        $mergedProperties = $existingProperties;
        foreach ($newProperties as $key => $value) {
            if ($value !== null) {
                $mergedProperties[$key] = $value;
            }
        }

        /**
         * @todo remove once they move to the new infra
         */
        if ($entity instanceof Lead) {
            // Retrieve legacy custom fields, ensure it's always an array
            $legacyCustomFields = (array) $entity->get('prefill-sign-docs', []);

            foreach ($mergedProperties as $key => $value) {
                $legacyCustomFields[$key] = $value;
            }

            // Only update if there are changes to legacyCustomFields
            if ($legacyCustomFields !== $entity->get('prefill-sign-docs', [])) {
                $entity->set('prefill-sign-docs', $legacyCustomFields);
            }
        }

        // Update existing activity or create new one
        if ($previousActivity) {
            $previousActivity->update([
                'properties' => $mergedProperties,
                'description' => $req['input']['description'] ?? $previousActivity->description,
            ]);

            return $previousActivity;
        } else {
            $activity = activity($logName)
                ->causedBy($user)
                ->performedOn($entity)
                ->withProperties($mergedProperties)
                ->log($req['input']['description'] ?? null);

            return $activity;
        }
    }
}
