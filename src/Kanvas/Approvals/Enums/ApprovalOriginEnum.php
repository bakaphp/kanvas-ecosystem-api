<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Enums;

/**
 * Where a request came from, recorded for audit and available to a policy's trigger condition.
 *
 * Always passed explicitly by the caller that knows — it is NOT derived from ambient state. There is
 * no container-bound "current agent" to read (HasKanvasContext::$actingAgent is tool-local), and an
 * agent's user is routinely shared across agents, so the actor cannot be inferred from the user either.
 * A policy that needs to gate on provenance without a caller threading it should condition on the
 * entity's own data instead — e.g. Scribe stamps source_email_message_id at creation.
 */
enum ApprovalOriginEnum: string
{
    case UI = 'ui';
    case API = 'api';
    case AGENT = 'agent';
    case EMAIL = 'email';
    case IMPORT = 'import';
    case SYSTEM = 'system';
}
