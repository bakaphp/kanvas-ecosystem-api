<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Handlers;

use Kanvas\Communication\Channels\Email\Providers\Mailgun\Handlers\MailgunHandler as CurrentMailgunHandler;

/**
 * @deprecated moved to {@see CurrentMailgunHandler}. Integration rows saved before the move name this class in `integrations.handler`.
 */
class MailgunHandler extends CurrentMailgunHandler
{
}
