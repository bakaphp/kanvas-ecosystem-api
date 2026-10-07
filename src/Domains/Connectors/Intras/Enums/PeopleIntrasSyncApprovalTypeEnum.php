<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Enums;

/**
 * Gates the SIPGO push only — independent of PeopleApprovalTypeEnum::CONTENT. Update-only: a People
 * with no INTRAS_PARTICIPANT_ID has no SIPGO row to write to, and creating participants from Kanvas
 * is not supported yet.
 */
enum PeopleIntrasSyncApprovalTypeEnum: string
{
    case UPDATE = 'approve_people_intras_update';
}
