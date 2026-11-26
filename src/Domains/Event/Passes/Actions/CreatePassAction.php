<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Actions;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Kanvas\Event\Events\Enums\EventPassScopeEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;
use Kanvas\Event\Passes\Enums\PassFormatEnum;
use Kanvas\Event\Passes\Services\PassMotiveService;

class CreatePassAction
{
    private const string PIN_ALPHABET = '0123456789';
    private const string CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const int MIN_CODE_LENGTH = 4;

    public function __construct(
        private Event $event,
        private EventVersion $eventVersion,
        private ?ParticipantPassMotive $motive = null,
        private ?int $participantId = null,
        private ?Carbon $expirationDate = null,
        private PassFormatEnum $format = PassFormatEnum::NUMERIC_PIN,
        private ?int $codeLength = null,
        private ?int $maxUses = null,
        private ?array $payload = null,
        private ?string $prefix = null,
        private bool $eventWide = false,
    ) {
        if ($this->codeLength !== null && $this->codeLength < self::MIN_CODE_LENGTH) {
            throw new InvalidArgumentException('Pass code length must be at least ' . self::MIN_CODE_LENGTH . '.');
        }
    }

    public function execute(): array
    {
        if ($this->eventVersion->end_at && $this->eventVersion->end_at->isPast()) {
            throw new Exception('Cannot issue a pass for an event that has already finished.');
        }

        $existingPass = ParticipantPass::where('event_id', $this->event->getId())
            ->where('event_version_id', $this->eventVersion->getId())
            ->when($this->participantId, fn ($q) => $q->where('participant_id', $this->participantId))
            ->where('apps_id', $this->event->apps_id)
            ->where('companies_id', $this->event->companies_id)
            ->whereNotNull('used_date')
            ->first();

        if ($existingPass) {
            throw new Exception('A pass for this participant has already been used. Cannot reissue a code for a used pass.');
        }

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
            'expiration_date' => $this->expirationDate ?? $this->eventVersion->end_at ?? now()->addDays(30),
            'used_date' => null,
            'max_uses' => $this->maxUses,
            'payload' => $this->payload === null ? null : json_encode($this->payload),
            'event_wide' => $this->eventWide,
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
            [$pass, $plainCode] = new self(
                $this->event,
                $this->eventVersion,
                $motive,
                $participant->getId(),
                $this->expirationDate,
                $this->format,
                $this->codeLength,
                $this->maxUses,
                $this->payload,
                $this->prefix,
                $this->eventWide,
            )->execute();

            $passes[$participant->getId()] = $plainCode;
        }

        [$pass, $plainCode] = new self(
            $this->event,
            $this->eventVersion,
            $motive,
            null,
            $this->expirationDate,
            $this->format,
            $this->codeLength,
            $this->maxUses,
            $this->payload,
            $this->prefix,
            $this->eventWide,
        )->execute();

        $passes['event'] = $plainCode;

        return $passes;
    }

    private function generatePlainCode(): string
    {
        if ($this->format === PassFormatEnum::NUMERIC_PIN) {
            return $this->generateUniqueCode(self::PIN_ALPHABET, $this->codeLength ?? 6);
        }

        return $this->generateUniqueCode(self::CODE_ALPHABET, $this->codeLength ?? 8);
    }

    private function generateUniqueCode(string $alphabet, int $length): string
    {
        $lastIndex = strlen($alphabet) - 1;

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, $lastIndex)];
            }
            $code = ($this->prefix === null ? '' : $this->prefix . '-') . $code;
            $lookup = new GenerateLookupAction(
                $this->event->app,
                $this->event->company,
                $code
            )->execute();
        } while (ParticipantPass::where('pin_lookup', $lookup)->exists());

        return $code;
    }
}
