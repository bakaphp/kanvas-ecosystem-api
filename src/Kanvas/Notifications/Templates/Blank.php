<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Notifications\Notification;

/**
 * @deprecated version 2 , move to DynamicKanvasNotification
 */
class Blank extends Notification
{
    /**
     * __construct
     */
    public function __construct(
        string $templateName,
        array $data,
        array $via,
        Model $user,
        public ?string $pathAttachment = null
    ) {
        parent::__construct($user);
        $this->setType('blank');
        $this->setTemplateName($templateName);
        $this->setData($data);

        if (! empty($via)) {
            $this->channels = $via;
        }
    }
}
