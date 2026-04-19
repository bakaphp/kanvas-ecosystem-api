<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Events;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\Actions\UpdateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as DataTransferObjectEvent;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class EventManagementMutation
{
    public function create(mixed $root, array $req): Event
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $input = $req['input'];

        $eventDto = DataTransferObjectEvent::fromMultiple(
            $app,
            $user,
            $user->getCurrentCompany(),
            $input,
        );

        $event = new CreateEventAction($eventDto)->execute();

        self::syncCustomFields($event, $input);
        self::syncTags($event, $input);
        self::syncFiles($event, $input);

        return $event->fresh();
    }

    public function update(mixed $root, array $req): Event
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        $event = Event::getByIdFromCompanyApp($req['id'], $company, $app);
        $eventVersion = $event->versions()->first();

        $updateData = array_filter([
            'name' => $input['name'] ?? null,
            'description' => $input['description'] ?? null,
            'resources_id' => $input['resources_id'] ?? null,
            'resources_type' => $input['resources_type'] ?? null,
            'dates' => $input['dates'] ?? null,
        ], fn ($value) => $value !== null);

        if (! empty($updateData) && $eventVersion !== null) {
            new UpdateEventAction($eventVersion, $updateData)->execute();
        }

        self::syncCustomFields($event, $input);
        self::syncTags($event, $input);
        self::syncFiles($event, $input);

        return $event->fresh();
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        return Event::getByIdFromCompanyApp($req['id'], $user->getCurrentCompany(), $app)->delete();
    }

    public function follow(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $user = Users::getById((int) $req['input']['user_id']);
        UsersRepository::belongsToThisApp($user, $app);

        /** @var Event $event */
        $event = Event::getByUuidFromCompanyApp(
            $req['input']['entity_id'],
            $user->getCurrentCompany(),
            $app,
        );

        return $user->follow($event) instanceof UsersFollows;
    }

    public function unFollow(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $user = Users::getById((int) $req['input']['user_id']);
        UsersRepository::belongsToThisApp($user, $app);

        /** @var Event $event */
        $event = Event::getByUuidFromCompanyApp(
            $req['input']['entity_id'],
            $user->getCurrentCompany(),
            $app,
        );

        return $user->unFollow($event);
    }

    protected static function syncCustomFields(Event $event, array $input): void
    {
        if (! empty($input['custom_fields']) && is_array($input['custom_fields'])) {
            $event->setAllCustomFields($input['custom_fields']);
        }
    }

    protected static function syncTags(Event $event, array $input): void
    {
        if (array_key_exists('tags', $input) && is_array($input['tags'])) {
            $tagNames = [];
            foreach ($input['tags'] as $tag) {
                if (is_array($tag) && isset($tag['name'])) {
                    $tagNames[] = (string) $tag['name'];
                } elseif (is_string($tag)) {
                    $tagNames[] = $tag;
                }
            }
            if (! empty($tagNames)) {
                $event->syncTags($tagNames);
            }
        }
    }

    protected static function syncFiles(Event $event, array $input): void
    {
        if (! empty($input['files']) && is_array($input['files'])) {
            $event->addMultipleFilesFromUrl($input['files']);
        }
    }
}
