<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Override;

abstract class AbstractTemplatedNotification extends Notification
{
    /**
     * Subclasses must redeclare this with their template slug.
     */
    public const string TEMPLATE_NAME = '';

    public array $channels = ['mail'];

    #[Override]
    public function getEmailContent(): string
    {
        return new RenderTemplateAction($this->app, $this->company)
            ->execute(static::TEMPLATE_NAME, $this->getData());
    }
}
