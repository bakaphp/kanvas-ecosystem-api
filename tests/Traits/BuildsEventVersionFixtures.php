<?php

declare(strict_types=1);

namespace Tests\Traits;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Support\Setup;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Spatie\LaravelData\DataCollection;

/**
 * Event versions with registrations, built through the real `createEvent` mutation so every
 * report reads the same rows production writes.
 */
trait BuildsEventVersionFixtures
{
    protected function runEventSetup(): void
    {
        $user = auth()->user();

        new Setup(app(Apps::class), $user, $user->getCurrentCompany())->run();
    }

    protected function createEventVersionWithParticipants(
        int $maxCapacity = 50,
        ?Carbon $eventDate = null,
        int $participantCount = 0,
    ): EventVersion {
        $this->runEventSetup();
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $eventDate ??= Carbon::now()->addWeeks(2);

        $input = [
            'name' => 'Test Event ' . uniqid(),
            'description' => 'Test',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [
                [
                    'date' => $eventDate->toDateString(),
                    'start_time' => '10:00',
                    'end_time' => '12:00',
                ],
            ],
        ];

        $createResponse = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) {
                    id
                    versions { data { id } }
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $eventVersion = EventVersion::find((int) $createResponse->json('data.createEvent.versions.data.0.id'));
        $eventVersion->start_at = $eventDate->toDateTimeString();
        $eventVersion->metadata = array_merge($eventVersion->metadata ?? [], [
            'max_capacity' => $maxCapacity,
        ]);
        $eventVersion->saveQuietly();

        $this->addParticipants($eventVersion, $participantCount);

        return $eventVersion->fresh();
    }

    /**
     * @param ParticipantType|null $type   the company's first type when null
     * @param Carbon|null          $registeredAt backdates the registration, which is what places
     *                                           it in a "weeks before the event" bucket
     */
    protected function addParticipants(
        EventVersion $eventVersion,
        int $count,
        ?ParticipantType $type = null,
        ?Carbon $registeredAt = null
    ): void {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $type ??= ParticipantType::fromCompany($company)->fromApp($app)->first();
        $themeArea = ThemeArea::fromCompany($company)->fromApp($app)->first();

        for ($i = 0; $i < $count; $i++) {
            $people = new CreatePeopleAction(new PeopleData(
                app: $app,
                branch: $user->getCurrentBranch(),
                user: $user,
                firstname: 'Test' . $i,
                lastname: 'Attendee' . uniqid(),
                contacts: Contact::collect([], DataCollection::class),
                address: Address::collect([], DataCollection::class),
            ))->execute();

            $participant = Participant::create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'users_id' => $user->getId(),
                'people_id' => $people->getId(),
                'theme_area_id' => $themeArea->getId(),
            ]);

            $registration = EventVersionParticipant::create([
                'event_version_id' => $eventVersion->getId(),
                'participant_id' => $participant->getId(),
                'participant_type_id' => $type->getId(),
                'ticket_price' => 0,
                'discount' => 0,
            ]);

            if ($registeredAt !== null) {
                $registration->created_at = $registeredAt;
                $registration->saveQuietly();
            }
        }
    }
}
