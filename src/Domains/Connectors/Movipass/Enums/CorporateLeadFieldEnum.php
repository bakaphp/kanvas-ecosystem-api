<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Enums;

/**
 * Namespaced with `movipass_` to avoid colliding with other connectors' workflows running
 * on the same lead.
 */
enum CorporateLeadFieldEnum: string
{
    case STATUS = 'movipass_corporate_status';
    case STATUS_REASON = 'movipass_corporate_status_reason';
    case COMPANY_ID = 'movipass_corporate_company_id';
    case INVITE_HASH = 'movipass_corporate_invite_hash';
    case VALIDATION_HINT = 'movipass_corporate_validation_hint';
    case REVIEWED_BY = 'movipass_corporate_reviewed_by';
    case REVIEWED_AT = 'movipass_corporate_reviewed_at';

    /**
     * Present only on upgrade requests from an already-registered user
     * (`enableCorporateMode`). Its presence is what tells the approval to grant the flag to
     * an existing user instead of creating a Company and an invite from scratch.
     */
    case UPGRADE_USER_ID = 'movipass_corporate_upgrade_users_id';

    /**
     * The company the user's products/TAGs migrate away from on approval. Captured at
     * request time because they may switch companies before an admin gets to it.
     */
    case UPGRADE_SOURCE_COMPANY_ID = 'movipass_corporate_upgrade_source_company_id';

    public const COMPANY_FIELDS = [
        'legal_name',
        'commercial_name',
        'rnc',
    ];

    /**
     * Stamped on the UsersInvite, then propagated to the User on invite acceptance by
     * PropagateCorporateFieldsToUserActivity — never onto the Company.
     */
    public const USER_FIELDS = [
        'is_corporate',
        'contact_name',
        'contact_role',
        'contact_email',
        'contact_phone',
    ];
}
