<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;

class Blank extends Notification
{
    /**
     * __construct
     */
    public function __construct(string $templateName, array $data, array $via)
    {
        $this->setType('blank');
        $this->setTemplateName($templateName);
        $this->setData($data);
        $this->setVia($via);
    }
}
