<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class Blank extends Notification implements ShouldQueue
{
    use Queueable;

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
