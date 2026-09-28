<?php

declare(strict_types=1);

namespace Kanvas\Templates\Actions;

use Kanvas\Templates\Models\Templates;

class UpdateTemplateAction
{
    public function __construct(
        protected Templates $template
    ) {
    }

    public function execute(
        ?string $template = null,
        ?string $subject = null,
        ?string $title = null
    ): Templates {
        if ($template !== null) {
            $this->template->template = $template;
        }

        if ($subject !== null) {
            $this->template->subject = $subject;
        }

        if ($title !== null) {
            $this->template->title = $title;
        }

        $this->template->saveOrFail();

        return $this->template;
    }
}
