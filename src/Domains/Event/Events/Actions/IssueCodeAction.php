<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Kanvas\Event\Events\Enums\EventPassScopeEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;
use Kanvas\Exceptions\ValidationException;

class IssueCodeAction
{
    public const FORMAT_NUMERIC_PIN = 'numeric';
    public const FORMAT_QR_CODE = 'qr';

    /**
     * Create a pass (internal method used by forEvent and forParticipant)
     */
    public static function createPass(
        Event $event,
        EventVersion $eventVersion,
        ParticipantPassMotive $motive,
        ?int $participantId = null,
        ?Carbon $expirationDate = null,
        string $format = self::FORMAT_NUMERIC_PIN
    ): array {
        $plainCode = $format === self::FORMAT_NUMERIC_PIN
            ? self::generatePIN($event->companies_id)
            : self::generateCode($event->companies_id);

        // Generate HMAC-based lookup using company_id and PIN
        $lookup = self::generateLookup($event->companies_id, $plainCode);

        $pass = ParticipantPass::create([
            'event_id' => $event->getId(),
            'event_version_id' => $eventVersion->getId(),
            'participant_id' => $participantId,
            'participant_pass_motive_id' => $motive->getId(),
            'apps_id' => $event->apps_id,
            'format' => $format,
            'companies_id' => $event->companies_id,
            'users_id' => $eventVersion->users_id,
            'code' => encrypt($plainCode),
            'pin_hash' => Hash::make($plainCode),
            'pin_lookup' => $lookup,
            'expiration_date' => $expirationDate ?? now()->addDays(30),
            'used_date' => null,
            'scope' => $participantId ? EventPassScopeEnum::PARTICIPANT->value : EventPassScopeEnum::EVENT->value,
        ]);

        return [$pass, $plainCode];
    }

    /**
     * Generate HMAC-based lookup key
     */
    private static function generateLookup(int $companyId, string $pin): string
    {
        $secret = config('app.key'); // Use app key as secret
        $hmac = hash_hmac('sha256', $companyId . '|' . $pin, $secret, true);

        // URL-safe base64 encoding
        $lookup = rtrim(strtr(base64_encode($hmac), '+/', '-_'), '=');

        // Truncate to 20 chars for shorter lookup
        return substr($lookup, 0, 20);
    }

    /**
     * Issue codes for all participants in an event
     */
    public static function forAllParticipants(
        EventVersion $eventVersion,
        ?ParticipantPassMotive $motive = null,
        ?Carbon $expirationDate = null,
        string $format = self::FORMAT_NUMERIC_PIN
    ): array {
        $passes = [];
        $participants = $eventVersion->participants;

        if ($participants->isEmpty()) {
            throw new ValidationException('No participants found for this event version.');
        }

        foreach ($participants as $participant) {
            [$pass, $plainCode] = self::createPass($eventVersion->event, $eventVersion, $motive, $participant->getId(), $expirationDate, $format);
            $passes[$participant->getId()] = $plainCode;
        }

        [$pass, $plainCode] = self::createPass($eventVersion->event, $eventVersion, $motive, null, $expirationDate, $format);
        $passes['event'] = $plainCode;

        return $passes;
    }

    /**
     * Scan and validate a PIN code
     */
    public static function scanPIN(string $pin, int $appsId, int $companiesId): ?ParticipantPass
    {
        // Generate lookup key from PIN
        $lookup = self::generateLookup($companiesId, $pin);

        // Fast lookup using HMAC-based key
        $pass = ParticipantPass::where('pin_lookup', $lookup)
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->first();

        if (! $pass) {
            throw new ValidationException('Invalid PIN code.');
        }

        // Verify PIN against hash for extra security
        if (! Hash::check($pin, $pass->pin_hash)) {
            throw new ValidationException('Invalid PIN code.');
        }

        // Check if expired
        if (now()->gt($pass->expiration_date)) {
            throw new ValidationException('PIN code has expired.');
        }

        // Check if already used
        if ($pass->used_date !== null) {
            throw new ValidationException('PIN code has already been used.');
        }

        return $pass;
    }

    /**
     * Mark a pass as used
     */
    public static function markAsUsed(ParticipantPass $pass): ParticipantPass
    {
        $pass->used_date = now();
        $pass->save();

        return $pass;
    }

    /**
     * Generate a unique code (PIN or QR-compatible string)
     */
    private static function generateCode(int $companyId, int $length = 8): string
    {
        do {
            $code = strtoupper(substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $length));
            $lookup = self::generateLookup($companyId, $code);
        } while (ParticipantPass::where('pin_lookup', $lookup)->exists());

        return $code;
    }

    /**
     * Generate a numeric PIN code
     */
    public static function generatePIN(int $companyId, int $length = 6): string
    {
        do {
            $pin = '';
            for ($i = 0; $i < $length; $i++) {
                $pin .= random_int(0, 9);
            }
            $lookup = self::generateLookup($companyId, $pin);
        } while (ParticipantPass::where('pin_lookup', $lookup)->exists());

        return $pin;
    }
}
