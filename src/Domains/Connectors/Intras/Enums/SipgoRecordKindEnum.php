<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Enums;

use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * The SIPGO records a person can point at with a link or a code. SIPGO shows the code as a two-letter
 * prefix on the row's primary key (`VE7160`, `PA80239`), and its URLs carry that same id
 * (`/events-versions/7160/edit`), so every kind is prefix + segment + the Kanvas field that keeps the id.
 * Verified against the live app on 2026-11-09. `SipgoEntityEnum` is the write-back side (tables and
 * custom-field modules); this one is the reading side.
 */
enum SipgoRecordKindEnum: string
{
    case EVENT = 'event';
    case VERSION = 'version';
    case PARTICIPANT = 'participant';
    case COMPANY = 'company';
    case PROPOSAL = 'proposal';
    case FACILITATOR = 'facilitator';

    public function urlSegment(): string
    {
        return match ($this) {
            self::EVENT => 'events',
            self::VERSION => 'events-versions',
            self::PARTICIPANT => 'participants',
            self::COMPANY => 'companies',
            self::PROPOSAL => 'inhouseproposals',
            self::FACILITATOR => 'facilitators',
        };
    }

    public function codePrefix(): ?string
    {
        return match ($this) {
            self::EVENT => 'EV',
            self::VERSION => 'VE',
            self::PARTICIPANT => 'PA',
            self::COMPANY => 'EM',
            self::PROPOSAL => 'PI',
            self::FACILITATOR => null,
        };
    }

    public function linkField(): CustomFieldEnum
    {
        return match ($this) {
            self::EVENT => CustomFieldEnum::INTRAS_EVENT_ID,
            self::VERSION => CustomFieldEnum::INTRAS_EVENT_VERSION_ID,
            self::PARTICIPANT => CustomFieldEnum::INTRAS_PARTICIPANT_ID,
            self::COMPANY => CustomFieldEnum::INTRAS_COMPANY_ID,
            self::PROPOSAL => CustomFieldEnum::INTRAS_QUOTE_ID,
            self::FACILITATOR => CustomFieldEnum::INTRAS_FACILITATOR_ID,
        };
    }

    /**
     * @return class-string
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::EVENT => Event::class,
            self::VERSION => EventVersion::class,
            self::PARTICIPANT, self::FACILITATOR => People::class,
            self::COMPANY => Organization::class,
            self::PROPOSAL => Lead::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::EVENT => 'evento',
            self::VERSION => 'versión',
            self::PARTICIPANT => 'ejecutivo',
            self::COMPANY => 'empresa',
            self::PROPOSAL => 'propuesta',
            self::FACILITATOR => 'facilitador',
        };
    }

    public static function fromUrlSegment(string $segment): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->urlSegment() === strtolower($segment)) {
                return $kind;
            }
        }

        return null;
    }

    public static function fromCodePrefix(string $prefix): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->codePrefix() === strtoupper($prefix)) {
                return $kind;
            }
        }

        return null;
    }
}
