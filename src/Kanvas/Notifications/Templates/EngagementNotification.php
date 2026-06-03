<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Illuminate\Database\Eloquent\Model;

class EngagementNotification extends Blank
{
    public function __construct(
        string $templateName,
        array $data,
        array $via,
        Model $entity,
        public ?array $pathAttachment = null
    ) {
        parent::__construct(
            $templateName,
            $data,
            $via,
            $entity,
            $pathAttachment
        );
        $this->setType('engagement_notification');
        $this->setTemplateName($templateName);
        $this->setData($data);

        if (! empty($via)) {
            $this->channels = $via;
        }
    }
}
