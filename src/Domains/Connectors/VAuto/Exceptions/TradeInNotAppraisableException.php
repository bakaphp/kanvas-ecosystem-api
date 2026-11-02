<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VAuto\Exceptions;

/**
 * An expected skip (a form without a VIN or year/make/model), not a vAuto fault: the activity
 * flags the run FAILED without reporting it.
 */
class TradeInNotAppraisableException extends VAutoException
{
}
