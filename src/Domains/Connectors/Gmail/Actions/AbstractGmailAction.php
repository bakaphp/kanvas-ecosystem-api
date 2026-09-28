<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Gmail\Actions;

use Baka\Contracts\AppInterface;
use Google\Service\Gmail as GmailService;
use Kanvas\Connectors\Gmail\Client;

/** Shared constructor + lazy authenticated-service resolution for every Gmail read/download action. */
abstract class AbstractGmailAction
{
    public function __construct(
        protected AppInterface $app,
        protected ?GmailService $service = null,
    ) {
    }

    protected function service(): GmailService
    {
        return $this->service ??= Client::getInstance($this->app);
    }
}
