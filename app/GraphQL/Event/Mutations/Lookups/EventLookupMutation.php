<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Lookups;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;

class EventLookupMutation
{
    public function createEventType(mixed $root, array $req): EventType
    {
        return self::createLookup(EventType::class, $req['input']);
    }

    public function updateEventType(mixed $root, array $req): EventType
    {
        return self::updateLookup(EventType::class, (int) $req['id'], $req['input']);
    }

    public function deleteEventType(mixed $root, array $req): bool
    {
        return self::deleteLookup(EventType::class, (int) $req['id']);
    }

    public function createEventClass(mixed $root, array $req): EventClass
    {
        return self::createLookup(EventClass::class, $req['input']);
    }

    public function updateEventClass(mixed $root, array $req): EventClass
    {
        return self::updateLookup(EventClass::class, (int) $req['id'], $req['input']);
    }

    public function deleteEventClass(mixed $root, array $req): bool
    {
        return self::deleteLookup(EventClass::class, (int) $req['id']);
    }

    public function createEventStatus(mixed $root, array $req): EventStatus
    {
        return self::createLookup(EventStatus::class, $req['input']);
    }

    public function updateEventStatus(mixed $root, array $req): EventStatus
    {
        return self::updateLookup(EventStatus::class, (int) $req['id'], $req['input']);
    }

    public function deleteEventStatus(mixed $root, array $req): bool
    {
        return self::deleteLookup(EventStatus::class, (int) $req['id']);
    }

    public function createEventTheme(mixed $root, array $req): Theme
    {
        return self::createLookup(Theme::class, $req['input']);
    }

    public function updateEventTheme(mixed $root, array $req): Theme
    {
        return self::updateLookup(Theme::class, (int) $req['id'], $req['input']);
    }

    public function deleteEventTheme(mixed $root, array $req): bool
    {
        return self::deleteLookup(Theme::class, (int) $req['id']);
    }

    public function createEventThemeArea(mixed $root, array $req): ThemeArea
    {
        return self::createLookup(ThemeArea::class, $req['input']);
    }

    public function updateEventThemeArea(mixed $root, array $req): ThemeArea
    {
        return self::updateLookup(ThemeArea::class, (int) $req['id'], $req['input']);
    }

    public function deleteEventThemeArea(mixed $root, array $req): bool
    {
        return self::deleteLookup(ThemeArea::class, (int) $req['id']);
    }

    public function createParticipantType(mixed $root, array $req): ParticipantType
    {
        return self::createLookup(ParticipantType::class, $req['input']);
    }

    public function updateParticipantType(mixed $root, array $req): ParticipantType
    {
        return self::updateLookup(ParticipantType::class, (int) $req['id'], $req['input']);
    }

    public function deleteParticipantType(mixed $root, array $req): bool
    {
        return self::deleteLookup(ParticipantType::class, (int) $req['id']);
    }

    /**
     * @template T of BaseModel
     * @param class-string<T> $modelClass
     * @return T
     */
    protected static function createLookup(string $modelClass, array $input): BaseModel
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $model = new $modelClass();
        $model->apps_id = $app->getId();
        $model->companies_id = $company->getId();
        $model->users_id = $user->getId();
        $model->name = $input['name'];

        if (array_key_exists('is_default', $input)) {
            $model->is_default = (int) (bool) $input['is_default'];
        }

        $model->saveOrFail();

        return $model;
    }

    /**
     * @template T of BaseModel
     * @param class-string<T> $modelClass
     * @return T
     */
    protected static function updateLookup(string $modelClass, int $id, array $input): BaseModel
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var T $model */
        $model = $modelClass::getByIdFromCompanyApp($id, $company, $app);

        if (array_key_exists('name', $input)) {
            $model->name = $input['name'];
        }

        if (array_key_exists('is_default', $input)) {
            $model->is_default = (int) (bool) $input['is_default'];
        }

        $model->saveOrFail();

        return $model;
    }

    /**
     * @param class-string<BaseModel> $modelClass
     */
    protected static function deleteLookup(string $modelClass, int $id): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var BaseModel $model */
        $model = $modelClass::getByIdFromCompanyApp($id, $company, $app);

        return (bool) $model->delete();
    }
}
