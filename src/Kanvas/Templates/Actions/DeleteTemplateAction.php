<?php

declare(strict_types=1);

namespace Kanvas\Templates\Actions;

use Kanvas\Templates\Models\Templates;

class DeleteTemplateAction
{
    public function __construct(
        protected Templates $template
    ) {
    }

    public function execute(): bool
    {
        return $this->template->softDelete();
    }
}
