<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Events;

use App\GraphQL\Concerns\SyncsEntityRelatedInput;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;

class EventVersionManagementMutation
{
    use SyncsEntityRelatedInput;

    public function create(mixed $root, array $req): EventVersion
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var Event $event */
        $event = Event::getByIdFromCompanyApp((int) $input['event_id'], $company, $app);

        $currency = null;
        if (! empty($input['currency_id'])) {
            $currency = Currencies::find((int) $input['currency_id']);
        }
        $currency ??= Currencies::where('code', $input['currency_code'] ?? 'USD')->first();

        $nextVersionNumber = (int) (EventVersion::where('event_id', $event->getId())->max('version_number') ?? 0) + 1;
        $slug = Str::slug(($input['name'] ?? $event->name) . '-v' . $nextVersionNumber . '-' . uniqid());

        $eventVersion = new EventVersion();
        $eventVersion->apps_id = $app->getId();
        $eventVersion->companies_id = $company->getId();
        $eventVersion->users_id = $user->getId();
        $eventVersion->event_id = $event->getId();
        $eventVersion->currency_id = $currency?->getId() ?? 0;
        $eventVersion->name = $input['name'] ?? $event->name;
        $eventVersion->version_number = $nextVersionNumber;
        $eventVersion->version = (string) ($input['version'] ?? $nextVersionNumber);
        $eventVersion->slug = $slug;
        $eventVersion->description = $input['description'] ?? null;
        $eventVersion->classification = $input['classification'] ?? null;
        $eventVersion->price_per_ticket = (float) ($input['price_per_ticket'] ?? 0);
        $eventVersion->start_at = $input['start_at'] ?? null;
        $eventVersion->end_at = $input['end_at'] ?? null;
        $eventVersion->agenda = $input['agenda'] ?? null;
        $eventVersion->metadata = self::buildMetadata($input);
        $eventVersion->total_attendees = 0;
        $eventVersion->saveOrFail();

        if (! empty($input['dates']) && is_array($input['dates'])) {
            foreach ($input['dates'] as $date) {
                EventVersionDate::create([
                    'event_version_id' => $eventVersion->getId(),
                    'users_id' => $user->getId(),
                    'event_date' => $date['date'],
                    'start_time' => $date['start_time'] ?? null,
                    'end_time' => $date['end_time'] ?? null,
                ]);
            }
        }

        self::syncEntityRelatedInput($eventVersion, $input);

        $eventVersion->refresh();

        return $eventVersion;
    }

    public function update(mixed $root, array $req): EventVersion
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var EventVersion $eventVersion */
        $eventVersion = EventVersion::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        $updatable = [
            'name',
            'description',
            'classification',
            'price_per_ticket',
            'start_at',
            'end_at',
            'agenda',
            'version',
            'event_status_id',
        ];

        foreach ($updatable as $key) {
            if (array_key_exists($key, $input)) {
                $eventVersion->{$key} = $input[$key];
            }
        }

        if (! empty($input['currency_id'])) {
            $currency = Currencies::find((int) $input['currency_id']);
            if ($currency !== null) {
                $eventVersion->currency_id = $currency->getId();
            }
        }

        if (self::hasMetadataKeys($input)) {
            $existingMetadata = is_array($eventVersion->metadata) ? $eventVersion->metadata : [];
            $eventVersion->metadata = array_merge($existingMetadata, self::buildMetadata($input));
        }

        $eventVersion->saveOrFail();

        if (array_key_exists('dates', $input) && is_array($input['dates'])) {
            $eventVersion->dates()->delete();
            foreach ($input['dates'] as $date) {
                EventVersionDate::create([
                    'event_version_id' => $eventVersion->getId(),
                    'users_id' => $user->getId(),
                    'event_date' => $date['date'],
                    'start_time' => $date['start_time'] ?? null,
                    'end_time' => $date['end_time'] ?? null,
                ]);
            }
        }

        self::syncEntityRelatedInput($eventVersion, $input);

        $eventVersion->refresh();

        $eventVersion->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            [
                'app' => $app,
            ],
        );

        return $eventVersion;
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        /** @var EventVersion $eventVersion */
        $eventVersion = EventVersion::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        return (bool) $eventVersion->delete();
    }

    public function follow(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $user = Users::getById((int) $req['input']['user_id']);
        UsersRepository::belongsToThisApp($user, $app);

        /** @var EventVersion $eventVersion */
        $eventVersion = EventVersion::getByUuidFromCompanyApp(
            $req['input']['entity_id'],
            $user->getCurrentCompany(),
            $app,
        );

        return $user->follow($eventVersion) instanceof UsersFollows;
    }

    public function unFollow(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $user = Users::getById((int) $req['input']['user_id']);
        UsersRepository::belongsToThisApp($user, $app);

        /** @var EventVersion $eventVersion */
        $eventVersion = EventVersion::getByUuidFromCompanyApp(
            $req['input']['entity_id'],
            $user->getCurrentCompany(),
            $app,
        );

        return $user->unFollow($eventVersion);
    }

    protected static function buildMetadata(array $input): array
    {
        $metadata = [];
        if (array_key_exists('max_capacity', $input)) {
            $metadata['max_capacity'] = (int) $input['max_capacity'];
        }
        if (array_key_exists('metadata', $input) && is_array($input['metadata'])) {
            $metadata = array_merge($metadata, $input['metadata']);
        }

        return $metadata;
    }

    protected static function hasMetadataKeys(array $input): bool
    {
        return array_key_exists('max_capacity', $input) || array_key_exists('metadata', $input);
    }

}
