<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Passes;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Actions\IssueCodeAction;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;

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

        $event = Event::getByIdFromCompanyApp($args['event_id'], $company, $app);
        $eventVersion = $event->versions()->firstOrFail();

        $motive = $this->getMotive($company, $app, $args['motive_id'] ?? null, $user->getId());

        $format = $args['format'] ?? IssueCodeAction::FORMAT_NUMERIC_PIN;
        $expirationDate = isset($args['expiration_date'])
            ? $args['expiration_date']
            : null;

        [$pass, $plainCode] = IssueCodeAction::createPass(
            $event,
            $eventVersion,
            $motive,
            null,
            $expirationDate,
            $format
        );

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

        $participant = Participant::where('id', $args['participant_id'])
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->firstOrFail();

        $eventVersion = EventVersion::where('id', $args['event_version_id'])
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->firstOrFail();

        $motive = $this->getMotive($company, $app, $args['motive_id'] ?? null, $user->getId());

        $format = $args['format'] ?? IssueCodeAction::FORMAT_NUMERIC_PIN;
        $expirationDate = isset($args['expiration_date'])
            ? $args['expiration_date']
            : null;

        [$pass, $plainCode] = IssueCodeAction::createPass(
            $eventVersion->event,
            $eventVersion,
            $motive,
            $participant->getId(),
            $expirationDate,
            $format
        );

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

        $eventVersion = EventVersion::where('id', $args['event_version_id'])
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->firstOrFail();

        $motive = $this->getMotive($company, $app, $args['motive_id'] ?? null, $user->getId());

        $format = $args['format'] ?? IssueCodeAction::FORMAT_NUMERIC_PIN;
        $expirationDate = isset($args['expiration_date'])
            ? $args['expiration_date']
            : null;

        $codes = IssueCodeAction::forAllParticipants(
            $eventVersion,
            $motive,
            $expirationDate,
            $format
        );

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

        $code = $args['code'];
        $format = $args['format'] ?? IssueCodeAction::FORMAT_NUMERIC_PIN;

        // Validate and retrieve the pass (works for both PIN and QR formats)
        $pass = IssueCodeAction::scanPIN($code, $app->getId(), $company->getId(), $format);

        // Mark the pass as used
        IssueCodeAction::markAsUsed($pass);

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
