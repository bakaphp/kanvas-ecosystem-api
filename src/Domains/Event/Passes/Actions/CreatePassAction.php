<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Kanvas\Event\Events\Enums\EventPassScopeEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;
use Kanvas\Event\Passes\Enums\PassFormatEnum;
use Kanvas\Event\Passes\Services\PassMotiveService;

class CreatePassAction
{
    public function __construct(
        private Event $event,
        private EventVersion $eventVersion,
        private ?ParticipantPassMotive $motive = null,
        private ?int $participantId = null,
        private ?Carbon $expirationDate = null,
        private PassFormatEnum $format = PassFormatEnum::NUMERIC_PIN
    ) {
    }

    public function execute(): array
    {
        $plainCode = $this->generatePlainCode();
        $lookup = new GenerateLookupAction(
            $this->event->app,
            $this->event->company,
            $plainCode
        )->execute();

        $motive = $this->motive ?? PassMotiveService::getMotive(
            $this->event->company,
            $this->event->app,
            'default',
            $this->eventVersion->users_id
        );

        $pass = ParticipantPass::firstOrCreate([
            'event_id' => $this->event->getId(),
            'event_version_id' => $this->eventVersion->getId(),
            'participant_id' => $this->participantId,
            'apps_id' => $this->event->apps_id,
            'companies_id' => $this->event->companies_id,
            'code' => encrypt($plainCode),
            'pin_hash' => Hash::make($plainCode),
            'pin_lookup' => $lookup,
        ], [
            'participant_pass_motive_id' => $motive->getId(),
            'format' => $this->format->value,
            'users_id' => $this->eventVersion->users_id,
            'expiration_date' => $this->expirationDate ?? now()->addDays(30),
            'used_date' => null,
            'scope' => $this->participantId
                ? EventPassScopeEnum::PARTICIPANT->value
                : EventPassScopeEnum::EVENT->value,
        ]);

        return [$pass, $plainCode];
    }

    public function forAllParticipants(): array
    {
        $passes = [];
        $participants = $this->eventVersion->participants;

        if ($participants->isEmpty()) {
            return [];
        }

        $motive = $this->motive ?? PassMotiveService::getMotive(
            $this->event->company,
            $this->event->app,
            'default',
            $this->eventVersion->users_id
        );

        foreach ($participants as $participant) {
            [$pass, $plainCode] = (new self(
                $this->event,
                $this->eventVersion,
                $motive,
                $participant->getId(),
                $this->expirationDate,
                $this->format
            ))->execute();

            $passes[$participant->getId()] = $plainCode;
        }

        // Create event-level pass
        [$pass, $plainCode] = (new self(
            $this->event,
            $this->eventVersion,
            $motive,
            null,
            $this->expirationDate,
            $this->format
        ))->execute();

        $passes['event'] = $plainCode;

        return $passes;
    }

    private function generatePlainCode(): string
    {
        if ($this->format === PassFormatEnum::NUMERIC_PIN) {
            return $this->generatePin();
        }

        return $this->generateCode();
    }

    private function generatePin(int $length = 6): string
    {
        do {
            $pin = '';
            for ($i = 0; $i < $length; $i++) {
                $pin .= random_int(0, 9);
            }
            $lookup = new GenerateLookupAction(
                $this->event->app,
                $this->event->company,
                $pin
            )->execute();
        } while (ParticipantPass::where('pin_lookup', $lookup)->exists());

        return $pin;
    }

    private function generateCode(int $length = 8): string
    {
        do {
            $code = strtoupper(substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $length));
            $lookup = new GenerateLookupAction(
                $this->event->app,
                $this->event->company,
                $code
            )->execute();
        } while (ParticipantPass::where('pin_lookup', $lookup)->exists());

        return $code;
    }
}
