<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Enums;

/**
 * CREATE has a policy and a type but no lane yet: organizations are created in SIPGO by hand until the
 * participant create lane has proven itself (docs/sipgo/sipgo-push-back-spec.md).
 */
enum OrganizationIntrasSyncApprovalTypeEnum: string
{
    case UPDATE = 'approve_organization_intras_update';
    case CREATE = 'approve_organization_intras_create';
}
