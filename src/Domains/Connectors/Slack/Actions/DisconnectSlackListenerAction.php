<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Services\SlackListenerReceiverService;

/**
 * Keeps the receiver row: the customer's Slack app points at that URL forever, so reconnecting has
 * to land on the same one.
 */
class DisconnectSlackListenerAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    public function execute(): bool
    {
        $receiver = new SlackListenerReceiverService()->findForCompany($this->app, $this->company);

        if ($receiver === null) {
            return false;
        }

        $configuration = $receiver->configuration;
        unset($configuration[ConfigurationEnum::BOT_TOKEN->value]);

        $receiver->configuration = $configuration;
        $receiver->is_active = false;
        $receiver->saveOrFail();

        return true;
    }
}
