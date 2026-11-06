<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\SalesAssist\Enums\LeadVehicleNoteEnum;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Throwable;

/**
 * Announces a new or changed trade-in / vehicle of interest on the lead's default channel: one message
 * saying it was added, then one with the custom field's value as stored. Each CRM shapes the vehicle
 * differently, so the value is posted untouched rather than parsed.
 *
 * Every CRM pull rewrites these custom fields with the same vehicle, so each announced vehicle is
 * fingerprinted on the lead and only one it has not seen produces a note. The fingerprint leaves out the
 * mileage, which the CRM refreshes on every pull, and the lead keeps several because a CRM with two
 * vehicles of interest sends them in turns. The lock covers two writers (a pull and the app) saving the
 * same vehicle at once.
 */
class RecordLeadVehicleNotesAction
{
    /**
     * The "... Added" line is an `ai-control` note like the AI mode changes; the vehicle data is a plain message.
     */
    private const string DATA_VERB = 'message';

    private const int MAX_FINGERPRINTS = 20;

    public function __construct(
        protected readonly Lead $lead,
        protected readonly LeadVehicleNoteEnum $kind,
    ) {
    }

    /**
     * For writes that save the lead with all its custom fields at once, where either vehicle may have changed.
     */
    public static function recordAll(Lead $lead): void
    {
        foreach (LeadVehicleNoteEnum::cases() as $kind) {
            new self($lead, $kind)->execute();
        }
    }

    /**
     * @return list<Message>
     */
    public function execute(): array
    {
        try {
            $vehicle = $this->lead->get($this->kind->customField());
            if ($vehicle === null || $vehicle === '' || $vehicle === []) {
                return [];
            }

            $content = is_scalar($vehicle)
                ? (string) $vehicle
                : (string) json_encode($vehicle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $fingerprint = $this->fingerprint($vehicle);
            if (in_array($fingerprint, $this->announcedFingerprints(), true)) {
                return [];
            }

            $lock = Cache::lock('lead-vehicle-note:' . $this->lead->getId() . ':' . $this->kind->value, 10);
            if (! $lock->get()) {
                return [];
            }

            try {
                $announced = $this->announcedFingerprints();
                if (in_array($fingerprint, $announced, true)) {
                    return [];
                }

                // Leads noted before fingerprints carry the md5 of the raw value instead: same vehicle, already announced.
                if ($this->lead->get($this->kind->noteHashKey()) === md5($content)) {
                    $this->rememberFingerprint($announced, $fingerprint);

                    return [];
                }

                $notes = array_values(array_filter([
                    $this->note($this->kind->title(), LeadMessageTypeEnum::AI_CONTROL->value),
                    $this->note($this->kind->label() . "\n" . $content, self::DATA_VERB, isPublic: false),
                ]));

                if ($notes !== []) {
                    $this->rememberFingerprint($announced, $fingerprint);
                }

                return $notes;
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Mileage left out and keys sorted, so a fresh odometer reading or a reordered payload is the same vehicle.
     */
    private function fingerprint(mixed $vehicle): string
    {
        $normalized = is_array($vehicle) ? $this->withoutMileage($vehicle) : trim((string) $vehicle);

        return md5((string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function withoutMileage(array $value): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/mileage|odometer/i', $key) === 1) {
                continue;
            }

            $normalized[$key] = is_array($item) ? $this->withoutMileage($item) : $item;
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function announcedFingerprints(): array
    {
        $stored = $this->lead->get($this->kind->noteFingerprintsKey());

        return is_array($stored) ? array_values(array_filter($stored, is_string(...))) : [];
    }

    /**
     * @param list<string> $announced
     */
    private function rememberFingerprint(array $announced, string $fingerprint): void
    {
        $announced[] = $fingerprint;

        $this->lead->set($this->kind->noteFingerprintsKey(), array_slice($announced, -self::MAX_FINGERPRINTS));
    }

    /**
     * The vehicle data note is created private: messages broadcast on create, so flipping it afterwards still leaks it.
     */
    private function note(string $body, string $verb, bool $isPublic = true): ?Message
    {
        return new RecordLeadNoteAction($this->lead)->execute(
            body: $body,
            tag: $this->kind->value,
            actingUser: $this->lead->user,
            fromIa: false,
            isPublic: $isPublic,
            messageTypeVerb: $verb,
        );
    }
}
