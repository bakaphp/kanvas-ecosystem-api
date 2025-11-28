<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Passes;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\TeeTime\Enums\EventStatusEnum;
use Kanvas\Event\Events\Actions\SendEventEmailsAction;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Passes\Actions\CreatePassAction;
use Kanvas\Event\Passes\Actions\ScanPassAction;
use Kanvas\Event\Passes\Enums\PassFormatEnum;
use Kanvas\Event\Passes\Services\PassMotiveService;
use Kanvas\Exceptions\ValidationException;

class EventPassCodeMutation
{
    /**
     * Issue a code for the entire event
     */
    public function issueEventCode(mixed $root, array $args): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $input = $args['input'];

        $event = Event::getByIdFromCompanyApp($input['event_id'], $company, $app);
        $eventVersion = $event->versions()->firstOrFail();

        $motive = PassMotiveService::getMotive($company, $app, $input['motive_id'] ?? null, $user->getId());

        $format = isset($input['format']) ? PassFormatEnum::from($input['format']) : PassFormatEnum::NUMERIC_PIN;
        $expirationDate = isset($input['expiration_date'])
            ? $input['expiration_date']
            : null;

        [$pass, $plainCode] = (new CreatePassAction(
            $event,
            $eventVersion,
            $motive,
            null,
            $expirationDate,
            $format
        ))->execute();

        return [
            'success' => true,
            'pass' => $pass,
            'code' => $plainCode, // Return plain code for display/sending
            'message' => 'Event code issued successfully',
        ];
    }

    /**
     * Issue a code for a specific participant
     */
    public function issueParticipantCode(mixed $root, array $args): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $input = $args['input'];

        $eventVersion = EventVersion::where('id', $input['event_version_id'])
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->firstOrFail();

        $participant = $eventVersion->participants()->where('participants.id', $input['participant_id'])->firstOrFail();

        $motive = PassMotiveService::getMotive($company, $app, $input['motive_id'] ?? null, $user->getId());

        $format = isset($input['format']) ? PassFormatEnum::from($input['format']) : PassFormatEnum::NUMERIC_PIN;
        $expirationDate = isset($input['expiration_date'])
            ? $input['expiration_date']
            : null;

        try {
            [$pass, $plainCode] = (new CreatePassAction(
                $eventVersion->event,
                $eventVersion,
                $motive,
                $participant->getId(),
                $expirationDate,
                $format
            ))->execute();

            (new SendEventEmailsAction($eventVersion, EmailTemplateEnum::PARTICIPANT_NOTIFICATION->value, [
                'code' => $plainCode,
            ]))->execute($participant);

            return [
                'success' => true,
                'pass' => $pass,
                'code' => $plainCode,
                'participant' => $participant,
                'message' => 'Participant code issued successfully',
            ];
        } catch (Exception $e) {
            throw new ValidationException('Error issuing code: ' . $e->getMessage());
        }
    }

    /**
     * Issue codes for all participants in an event
     */
    public function issueAllParticipantCodes(mixed $root, array $args): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $input = $args['input'];

        $eventVersion = EventVersion::where('id', $input['event_version_id'])
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->firstOrFail();

        $motive = PassMotiveService::getMotive($company, $app, $input['motive_id'] ?? null, $user->getId());

        $format = isset($input['format']) ? PassFormatEnum::from($input['format']) : PassFormatEnum::NUMERIC_PIN;
        $expirationDate = isset($input['expiration_date'])
            ? $input['expiration_date']
            : null;

        $codes = (new CreatePassAction(
            $eventVersion->event,
            $eventVersion,
            $motive,
            null,
            $expirationDate,
            $format
        ))->forAllParticipants();

        return [
            'success' => true,
            'codes' => $codes, // Array of participant_id => plain_code
            'total_issued' => count($codes),
            'message' => 'Codes issued successfully for all participants',
        ];
    }

    /**
     * Check in using a PIN or QR code
     * Works for both event-level and participant-level passes
     */
    public function checkInWithPin(mixed $root, array $args): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $input = $args['input'];

        $code = $input['code'];
        $format = isset($input['format']) ? PassFormatEnum::from($input['format']) : PassFormatEnum::NUMERIC_PIN;

        // Validate and retrieve the pass (works for both PIN and QR formats)
        $pass = new ScanPassAction($app, $company, $code, $format)->execute();

        // Mark the pass as used
        $pass->used_date = now();
        $pass->save();

        // Load relationships
        $pass->load(['event', 'eventVersion', 'participant', 'motive']);

        // Determine if this is an event-level or participant-level check-in
        $isEventLevel = is_null($pass->participant_id);

        $validatedStatus = EventStatus::firstOrCreate([
            'companies_id' => $pass->eventVersion->companies_id,
            'apps_id' => $pass->eventVersion->apps_id,
            'name' => EventStatusEnum::VALIDATED->value,
        ], [
            'users_id' => $pass->eventVersion->users_id,
        ]);

        $pass->eventVersion->event->update(['event_status_id' => $validatedStatus->id]);

        return [
            'success' => true,
            'message' => $isEventLevel
                ? 'Event check-in successful'
                : 'Participant check-in successful',
            'pass' => $pass,
            'participant' => $pass->participant,
            'event' => $pass->event,
            'event_version' => $pass->eventVersion,
            'is_event_level' => $isEventLevel,
            'checked_in_at' => $pass->used_date->toDateTimeString(),
            'motive' => $pass->motive,
        ];
    }
}
