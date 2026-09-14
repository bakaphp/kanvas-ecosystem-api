<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Exceptions;

use Exception;

/**
 * A leads_participants row whose peoples_id does not resolve. It fails loudly instead of degrading
 * because the alternative is the participant's files vanishing from the payload with nothing logged.
 *
 * @todo emit this to the audit trail (ledger) as well as throwing, so orphan participants can be
 *       tracked per app/company without waiting for someone to open the lead
 */
class MissingParticipantPeopleIdException extends Exception
{
}
