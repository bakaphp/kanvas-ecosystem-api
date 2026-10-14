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
 * Every CRM pull rewrites these custom fields with the same vehicle, so the last announced value is
 * fingerprinted on the lead and only a different vehicle produces a note. The lock covers two writers
 * (a pull and the app) saving the same vehicle at once.
 */
class RecordLeadVehicleNotesAction
{
    /**
     * The "... Added" line is an `ai-control` note like the AI mode changes; the vehicle data is a plain message.
     */
    private const string DATA_VERB = 'message';

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

            $hash = md5($content);
            if ($this->lead->get($this->kind->noteHashKey()) === $hash) {
                return [];
            }

            $lock = Cache::lock('lead-vehicle-note:' . $this->lead->getId() . ':' . $this->kind->value, 10);
            if (! $lock->get()) {
                return [];
            }

            try {
                if ($this->lead->get($this->kind->noteHashKey()) === $hash) {
                    return [];
                }

                $notes = array_values(array_filter([
                    $this->note($this->kind->title(), LeadMessageTypeEnum::AI_CONTROL->value),
                    $this->note($this->kind->label() . "\n" . $content, self::DATA_VERB),
                ]));

                if ($notes !== []) {
                    $this->lead->set($this->kind->noteHashKey(), $hash);
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

    private function note(string $body, string $verb): ?Message
    {
        return new RecordLeadNoteAction($this->lead)->execute(
            body: $body,
            tag: $this->kind->value,
            actingUser: $this->lead->user,
            fromIa: false,
            messageTypeVerb: $verb,
        );
    }
}
