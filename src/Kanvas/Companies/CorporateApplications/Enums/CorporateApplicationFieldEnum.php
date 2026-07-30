<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Enums;

use Illuminate\Database\Eloquent\Model;

/**
 * Bookkeeping the approval flow writes onto the entity carrying the application (a Lead
 * today).
 *
 * These keys were originally `movipass_corporate_*` because the flow shipped inside that
 * connector. They are read with a fallback to the old name so applications filed before the
 * move stay visible in the queue; writes always use the current key. Drop `legacyKey()` once
 * `apps_custom_fields` has been backfilled — see the note in the class docblock of
 * ApproveCorporateApplicationAction.
 */
enum CorporateApplicationFieldEnum: string
{
    case STATUS = 'corporate_application_status';
    case STATUS_REASON = 'corporate_application_status_reason';
    case COMPANY_ID = 'corporate_application_company_id';
    case INVITE_HASH = 'corporate_application_invite_hash';
    case VALIDATION_HINT = 'corporate_application_validation_hint';
    case REVIEWED_BY = 'corporate_application_reviewed_by';
    case REVIEWED_AT = 'corporate_application_reviewed_at';

    /**
     * Present only on upgrade requests from an already-registered user. Its presence is what
     * tells the approval to grant to an existing user instead of creating a Company and an
     * invite from scratch.
     */
    case UPGRADE_USER_ID = 'corporate_application_upgrade_users_id';

    /**
     * The company the applicant's products migrate away from on approval. Captured at request
     * time because they may switch companies before an admin gets to it.
     */
    case UPGRADE_SOURCE_COMPANY_ID = 'corporate_application_upgrade_source_company_id';

    /**
     * Legal-entity identity, copied from the application onto the corporate Company.
     */
    public const COMPANY_FIELDS = [
        'legal_name',
        'commercial_name',
        'rnc',
    ];

    /**
     * Stamped on the UsersInvite, then propagated to the User on invite acceptance — never
     * onto the Company.
     */
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
