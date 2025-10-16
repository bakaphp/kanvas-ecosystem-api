<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Passes;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;
use Kanvas\Event\Passes\Actions\CreatePassAction;
use Kanvas\Event\Passes\Actions\ScanPassAction;
use Kanvas\Event\Passes\Enums\PassFormatEnum;

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

        $motive = $this->getMotive($company, $app, $input['motive_id'] ?? null, $user->getId());

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

        $participant = Participant::where('id', $input['participant_id'])
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->firstOrFail();

        $eventVersion = EventVersion::where('id', $input['event_version_id'])
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->firstOrFail();

        $motive = $this->getMotive($company, $app, $input['motive_id'] ?? null, $user->getId());

        $format = isset($input['format']) ? PassFormatEnum::from($input['format']) : PassFormatEnum::NUMERIC_PIN;
        $expirationDate = isset($input['expiration_date'])
            ? $input['expiration_date']
            : null;

        [$pass, $plainCode] = (new CreatePassAction(
            $eventVersion->event,
            $eventVersion,
            $motive,
            $participant->getId(),
            $expirationDate,
            $format
        ))->execute();

        return [
            'success' => true,
            'pass' => $pass,
            'code' => $plainCode,
            'participant' => $participant,
            'message' => 'Participant code issued successfully',
        ];
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

        $motive = $this->getMotive($company, $app, $input['motive_id'] ?? null, $user->getId());

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


    private function getMotive(Companies $company, Apps $app, $motiveId, $userId): ParticipantPassMotive
    {
        $motive = ParticipantPassMotive::fromCompany($company)
            ->fromApp($app)
            ->find($motiveId);

        if (! $motive) {
            $motive = ParticipantPassMotive::fromCompany($company)
                ->fromApp($app)
                ->firstOrCreate([
                    'name' => 'Default',
                ], [
                    'users_id' => $userId,
                ]);
        }

        return $motive;
    }
}
