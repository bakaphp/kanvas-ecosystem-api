<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;
use stdClass;
use Illuminate\Database\Eloquent\Model;


class Blank extends Notification
{
    /**
     * __construct
     */
    public function __construct(string $templateName, array $data, array $via, Model $user)
    {
        parent::__construct($user);
        $this->setType('blank');
        $this->setTemplateName($templateName);
        $this->setData($data);
        $this->setVia($via);
    }
}
