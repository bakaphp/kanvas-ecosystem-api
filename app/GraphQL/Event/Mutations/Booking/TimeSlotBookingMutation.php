<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Booking;

use Baka\Support\IPInfo;
use Baka\Support\Str;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Actions\BuildEventDataAction;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\Actions\UpdateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventDto;
use Kanvas\Event\Events\Enums\TimeSlotStatusEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Events\Services\TimeSlotBookingLock;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\DataTransferObject\EventAccess;
use Kanvas\Event\Passes\DataTransferObject\WalkInAccess;
use Kanvas\Event\Passes\Services\InviteCodeGate;
use Kanvas\Event\Passes\Services\OpenWalkInVisitGuard;
use Kanvas\Event\Passes\Services\PassAttemptLimiter;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class TimeSlotBookingMutation
{
    /**
     * Book a time slot and create event with participants using existing CreateEventAction.
     */
    public function book(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): EventVersion
    {
        $user = auth()->user();
        $userCompany = $user->getCurrentCompany();
        $app = app(Apps::class);

        $input = $args['input'];

        $timeSlot = $this->findBookableSlot((int) $input['time_slot_id'], $userCompany, $app);
        $access = EventAccess::forResource($timeSlot->resource);
        $walkIn = WalkInAccess::forResource($timeSlot->resource);
        $company = $access === null && $walkIn === null ? $userCompany : $timeSlot->company;

        $this->assertSlotIsBookable($timeSlot, $access, $walkIn);

        $gate = $access === null ? null : new InviteCodeGate(
            $access,
            $app,
            $company,
            new PassAttemptLimiter('invite-code', $user->getId(), IPInfo::getClientIp())
        );
        $inviteCode = Str::trimToNull($input['invite_code'] ?? null);
        unset($input['invite_code']);

        $gate?->verify($inviteCode, $timeSlot);

        $claimInvite = $gate === null
            ? null
            : fn (EventVersion $booking) => $gate->redeem($inviteCode, $timeSlot, $booking);

        $createBooking = fn (): EventVersion => $this->createLockedBooking(
            TimeSlots::getById($timeSlot->id, $app),
            $input,
            $user,
            $company,
            $app,
            $claimInvite,
            $walkIn
        );

        return TimeSlotBookingLock::run(
            [$timeSlot->id],
            fn (): EventVersion => $walkIn === null
                ? $createBooking()
                : OpenWalkInVisitGuard::run($app, $input['metadata'] ?? [], $createBooking)
        );
    }

    private function assertSlotIsBookable(TimeSlots $timeSlot, ?EventAccess $access, ?WalkInAccess $walkIn): void
    {
        if ($access !== null || $walkIn !== null) {
            if ($timeSlot->end_at->isPast()) {
                throw new ValidationException(
                    'This time slot has ended and can no longer be booked. Slot end: ' . $timeSlot->end_at->toDateTimeString()
                );
            }

            return;
        }

        if ($timeSlot->hasStarted()) {
            throw new ValidationException(
                'This time slot has already started and can no longer be booked. Slot start: ' . $timeSlot->start_at->toDateTimeString()
            );
        }
    }

    private function findBookableSlot(int $id, Companies $userCompany, Apps $app): TimeSlots
    {
        $timeSlot = TimeSlots::getById($id, $app);
        $ownedByUser = (int) $timeSlot->companies_id === $userCompany->getId();

        $resource = $timeSlot->resource;

        if (! $ownedByUser && EventAccess::forResource($resource) === null && WalkInAccess::forResource($resource) === null) {
            throw new ModelNotFoundException()->setModel(TimeSlots::class, [$id]);
        }

        return $timeSlot;
    }

    private function createLockedBooking(
        TimeSlots $timeSlot,
        array $input,
        Users $user,
        Companies $company,
        Apps $app,
        ?Closure $claimInvite = null,
        ?WalkInAccess $walkIn = null
    ): EventVersion {
        if ($claimInvite !== null || ($walkIn !== null && empty($input['participants']))) {
            $input['participants'] = [$this->guestParticipant($user)];
        }

        $participantCount = count($input['participants'] ?? []);
        if (! $timeSlot->hasAvailableCapacity($participantCount)) {
            throw new ValidationException('Time slot does not have enough available capacity. Available: ' . $timeSlot->getAvailableSlots() . ', Required: ' . $participantCount);
        }

        $bookingData = $input;
        $bookingData['resources_id'] = $timeSlot->resources_id;
        $bookingData['resources_type'] = $timeSlot->resources_type;
        $bookingData['start_at'] = $timeSlot->start_at->toDateTimeString();
        $bookingData['end_at'] = $timeSlot->end_at->toDateTimeString();
        $bookingData['time_slot_id'] = $timeSlot->id;

        $resource = $timeSlot->resource;

        if (! $resource) {
            throw new ValidationException('Resource not found for the given time slot.');
        }

        $eventData = new BuildEventDataAction($resource, $user, $company, $bookingData)->execute();
        $eventData['start_at'] = $bookingData['start_at'];
        $eventData['end_at'] = $bookingData['end_at'];
        $eventDto = EventDto::from($app, $user, $company, $eventData);
        $metadata = $bookingData['metadata'] ?? [];
        unset($metadata['invite_code']);
        $metadata['time_slot_id'] = $timeSlot->id;
        $metadata['resource_name'] = $resource?->name ?? '';
        $metadata['resource_type'] = $bookingData['resources_type'];
        $metadata['slug_suffix'] = Str::uuid()->toString();

        if (isset($bookingData['hold_id'])) {
            $metadata['hold_id'] = $bookingData['hold_id'];
        }

        $createEventAction = new CreateEventAction($eventDto, $metadata);

        if ($claimInvite !== null) {
            $createEventAction->claimingBookingWith($claimInvite);
        }

        $event = $createEventAction->execute();

        $eventVersion = $event->versions->first();

        $newStatus = $timeSlot->isFullyBooked()
            ? TimeSlotStatusEnum::BOOKED->value
            : TimeSlotStatusEnum::OPEN->value;

        $timeSlot->update(['status' => $newStatus]);

        return $eventVersion;
    }

    public function cancel(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): EventVersion
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $eventVersion = EventVersion::getById((int) $args['id'], $app);

        if ($eventVersion->time_slot_id === null) {
            throw new ValidationException('This booking is not associated with a time slot.');
        }

        if (! $eventVersion->isManagedBy($user)) {
            throw new ModelNotFoundException()->setModel(EventVersion::class, [$args['id']]);
        }

        return new CancelEventAction($eventVersion)->execute();
    }

    private function guestParticipant(Users $user): array
    {
        return [
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'contacts' => [
                ['contacts_types_id' => ContactTypeEnum::EMAIL->value, 'value' => $user->email, 'weight' => 1],
            ],
        ];
    }

    /**
     * Update a time slot booking by changing to a new time slot.
     */
    public function update(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): EventVersion
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $input = $args['input'];

        // Get the existing event version
        $eventVersion = EventVersion::fromApp($app)
            ->fromCompany($company)
            ->findOrFail($input['event_version_id']);

        if (ParticipantPass::query()->where('redeemed_event_version_id', $eventVersion->getId())->exists()) {
            throw new ValidationException('An invite booking cannot be moved. Cancel it and book again.');
        }

        $oldTimeSlotId = $eventVersion->time_slot_id ?? null;

        if (! $oldTimeSlotId) {
            throw new ValidationException('This booking is not associated with a time slot.');
        }

        $oldTimeSlot = TimeSlots::fromApp($app)
            ->fromCompany($company)
            ->findOrFail($oldTimeSlotId);

        // Get the new time slot
        $newTimeSlot = TimeSlots::fromApp($app)
            ->fromCompany($company)
            ->findOrFail($input['new_time_slot_id']);

        if ($newTimeSlot->hasStarted()) {
            throw new ValidationException('This time slot has already started and can no longer be booked. Slot start: ' . $newTimeSlot->start_at->toDateTimeString());
        }

        return TimeSlotBookingLock::run(
            [$oldTimeSlot->id, $newTimeSlot->id],
            fn (): EventVersion => $this->moveLockedBooking(
                $eventVersion,
                $oldTimeSlot->refresh(),
                $newTimeSlot->refresh(),
                $input
            )
        );
    }

    private function moveLockedBooking(
        EventVersion $eventVersion,
        TimeSlots $oldTimeSlot,
        TimeSlots $newTimeSlot,
        array $input
    ): EventVersion {
        $participantCount = count($input['participants'] ?? $eventVersion->participants ?? []);
        if (! $newTimeSlot->hasAvailableCapacity($participantCount)) {
            throw new ValidationException('New time slot does not have enough available capacity. Available: ' . $newTimeSlot->getAvailableSlots() . ', Required: ' . $participantCount);
        }

        $updateData = [
            'dates' => [
                [
                    'date' => $newTimeSlot->start_at->toDateString(),
                    'start_time' => $newTimeSlot->start_at->format('H:i'),
                    'end_time' => $newTimeSlot->end_at->format('H:i'),
                ],
            ],
            'start_at' => $newTimeSlot->start_at->toDateTimeString(),
            'end_at' => $newTimeSlot->end_at->toDateTimeString(),
            'time_slot_id' => $newTimeSlot->id,
        ];

        if (isset($input['event_name'])) {
            $updateData['name'] = $input['event_name'];
        }

        if (isset($input['event_description'])) {
            $updateData['description'] = $input['event_description'];
        }

        if (isset($input['participants'])) {
            $updateData['participants'] = $input['participants'];
        }

        if (isset($input['resources'])) {
            $updateData['resources'] = $input['resources'];
        }

        $metadata = [
            'time_slot_id' => $newTimeSlot->id,
            'resource_name' => $newTimeSlot->resource?->name ?? '',
            'resource_type' => $newTimeSlot->resources_type,
        ];

        if (isset($input['metadata'])) {
            $metadata = array_merge($metadata, $input['metadata']);
        }

        $updateData['time_slot_id'] = $newTimeSlot->id;

        $updateData['metadata'] = $metadata;

        $updatedEventVersion = new UpdateEventAction($eventVersion, $updateData)->execute();

        $oldTimeSlot->autoUpdateStatus();
        $newTimeSlot->autoUpdateStatus();

        return $updatedEventVersion;
    }
}
