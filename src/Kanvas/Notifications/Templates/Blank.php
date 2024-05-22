<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Notifications\Notification;

class Blank extends Notification
{
    /**
     * __construct
     */
    public function __construct(
        string $templateName,
        array $data,
        array $via,
        Model $entity,
        public ?array $pathAttachment = null
    ) {
        parent::__construct($entity, $data);
        $this->setType('blank');
        $this->setTemplateName($templateName);
        $this->setData($data);
        
        if (! empty($via)) {
            $this->channels = $via;
        }
    }
}
