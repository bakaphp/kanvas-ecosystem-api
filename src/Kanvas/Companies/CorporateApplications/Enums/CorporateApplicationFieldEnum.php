<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Enums;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;

enum CorporateApplicationFieldEnum: string
{
    case STATUS = 'corporate_application_status';
    case STATUS_REASON = 'corporate_application_status_reason';
    case COMPANY_ID = 'corporate_application_company_id';
    case INVITE_HASH = 'corporate_application_invite_hash';
    case VALIDATION_HINT = 'corporate_application_validation_hint';
    case REVIEWED_BY = 'corporate_application_reviewed_by';
    case REVIEWED_AT = 'corporate_application_reviewed_at';
    case OVERDUE_AT = 'corporate_application_overdue_at';
    case UPGRADE_USER_ID = 'corporate_application_upgrade_users_id';
    case UPGRADE_SOURCE_COMPANY_ID = 'corporate_application_upgrade_source_company_id';

    public const COMPANY_FIELDS = [
        'legal_name',
        'commercial_name',
        'rnc',
    ];

    public const USER_FIELDS = [
        'is_corporate',
        ...self::USER_PROFILE_FIELDS,
    ];

    public const USER_PROFILE_FIELDS = [
        'contact_name',
        'contact_role',
        'contact_email',
        'contact_phone',
    ];

    public const REQUIRED_FIELDS = [
        'legal_name',
        'rnc',
        'contact_email',
    ];

    public const string RECEIVER_REQUIRED_KEY = 'application_required_fields';
    public const string RECEIVER_COMPANY_KEY = 'application_company_fields';
    public const string RECEIVER_USER_KEY = 'application_user_fields';

    private const LEAD_COLUMN_FALLBACK = [
        'contact_email' => 'email',
        'contact_phone' => 'phone',
        'contact_name' => 'firstname',
    ];

    public function legacyKey(): string
    {
        return 'movipass_corporate_' . str_replace('corporate_application_', '', $this->value);
    }

    public function readFrom(Model $entity): mixed
    {
        return $entity->get($this->value) ?? $entity->get($this->legacyKey());
    }

    public function writeTo(Model $entity, mixed $value): void
    {
        $entity->set($this->value, $value);
    }

    public static function requiredFor(?LeadReceiver $receiver): array
    {
        return self::receiverList($receiver, self::RECEIVER_REQUIRED_KEY, self::REQUIRED_FIELDS);
    }

    public static function companyFieldsFor(?LeadReceiver $receiver): array
    {
        return self::receiverList($receiver, self::RECEIVER_COMPANY_KEY, self::COMPANY_FIELDS);
    }

    public static function userFieldsFor(?LeadReceiver $receiver): array
    {
        return self::receiverList($receiver, self::RECEIVER_USER_KEY, self::USER_PROFILE_FIELDS);
    }

    public static function readApplication(Lead $application, string $key): mixed
    {
        $value = $application->get($key);
        $column = self::LEAD_COLUMN_FALLBACK[$key] ?? null;

        return $value ?: ($column === null ? null : $application->{$column});
    }

    public static function missing(array $required, callable $read): array
    {
        return array_values(array_filter(
            $required,
            fn (string $key): bool => Str::trimToNull((string) $read($key)) === null,
        ));
    }

    private static function receiverList(?LeadReceiver $receiver, string $key, array $default): array
    {
        $configured = $receiver?->get($key);

        if (is_string($configured)) {
            $configured = array_map('trim', explode(',', $configured));
        }

        if (! is_array($configured)) {
            return $default;
        }

        $keys = array_values(array_filter($configured, fn ($key): bool => is_string($key) && $key !== ''));

        return $keys === [] ? $default : $keys;
    }

    public static function copy(array $keys, callable $read, Model $target): array
    {
        $copied = [];

        foreach ($keys as $key) {
            $value = $read($key);

            if ($value === null || $value === '') {
                continue;
            }

            $target->set($key, $value);
            $copied[] = $key;
        }

        return $copied;
    }
}
