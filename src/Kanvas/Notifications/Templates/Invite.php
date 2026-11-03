<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\AdminLinks\Services\AdminLinkService;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Kanvas\Templates\Support\PlainData;
use Kanvas\Users\Models\AdminInvite;
use Override;

class Invite extends Notification
{
    public ?string $templateName = EmailTemplateEnum::USER_INVITE->value;

    #[Override]
    public function getData(): array
    {
        $data = [
           ...parent::getData(),
            'fromUser' => $this->getFromUser(),
        ];

        // Every stored invite template (per-app rows included) links through app.frontend_url, so a
        // console-sent invite swaps that host here instead of forking the templates.
        if ($this->isConsoleAdminInvite()) {
            $consoleHost = new AdminLinkService()->consoleHost($this->app);

            if ($consoleHost !== null) {
                $data['app'] = [...PlainData::from($this->app), 'frontend_url' => $consoleHost];
            }
        }

        return $data;
    }

    /**
     * @phpstan-assert-if-true Apps $this->app
     */
    private function isConsoleAdminInvite(): bool
    {
        return $this->app instanceof Apps
            && $this->entity instanceof AdminInvite
            && $this->entity->isForConsole();
    }
}
