<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Enums;

/**
 * Looked up by name, company first: a company that saves its own template under one of these names
 * (an admin, or an agent through create_template) replaces the app's copy without any other wiring.
 */
enum ApprovalEmailTemplateEnum: string
{
    case REQUESTED = 'approval-requested';
    case DIGEST = 'approval-digest';
}
