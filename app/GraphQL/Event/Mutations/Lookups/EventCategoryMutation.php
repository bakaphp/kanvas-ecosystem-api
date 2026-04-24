<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Lookups;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventType;

class EventCategoryMutation
{
    public function create(mixed $root, array $req): EventCategory
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        $eventType = isset($input['event_type_id'])
            ? EventType::getByIdFromCompanyApp((int) $input['event_type_id'], $company, $app)
            : null;
        $eventClass = isset($input['event_class_id'])
            ? EventClass::getByIdFromCompanyApp((int) $input['event_class_id'], $company, $app)
            : null;
        $parent = isset($input['parent_id'])
            ? EventCategory::getByIdFromCompanyApp((int) $input['parent_id'], $company, $app)
            : null;

        $category = new EventCategory();
        $category->apps_id = $app->getId();
        $category->companies_id = $company->getId();
        $category->users_id = $user->getId();
        $category->name = $input['name'];
        $category->event_type_id = $eventType?->getId();
        $category->event_class_id = $eventClass?->getId();
        $category->parent_id = $parent?->getId();
        $category->position = (int) ($input['position'] ?? 0);
        $category->saveOrFail();

        return $category;
    }

    public function update(mixed $root, array $req): EventCategory
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var EventCategory $category */
        $category = EventCategory::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        if (array_key_exists('name', $input)) {
            $category->name = $input['name'];
        }
        if (array_key_exists('event_type_id', $input)) {
            $category->event_type_id = $input['event_type_id']
                ? EventType::getByIdFromCompanyApp((int) $input['event_type_id'], $company, $app)->getId()
                : null;
        }
        if (array_key_exists('event_class_id', $input)) {
            $category->event_class_id = $input['event_class_id']
                ? EventClass::getByIdFromCompanyApp((int) $input['event_class_id'], $company, $app)->getId()
                : null;
        }
        if (array_key_exists('parent_id', $input)) {
            $category->parent_id = $input['parent_id']
                ? EventCategory::getByIdFromCompanyApp((int) $input['parent_id'], $company, $app)->getId()
                : null;
        }
        if (array_key_exists('position', $input)) {
            $category->position = (int) $input['position'];
        }

        $category->saveOrFail();

        return $category;
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var EventCategory $category */
        $category = EventCategory::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        return (bool) $category->delete();
    }
}
