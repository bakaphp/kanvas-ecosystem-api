<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Enums;

use Illuminate\Database\Eloquent\Model;

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
        'contact_name',
        'contact_role',
        'contact_email',
        'contact_phone',
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
}
