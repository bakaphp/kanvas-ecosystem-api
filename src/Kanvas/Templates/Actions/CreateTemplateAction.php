<?php

declare(strict_types=1);

namespace Kanvas\Templates\Actions;

use Kanvas\Enums\AppEnums;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Models\Templates;

class CreateTemplateAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected TemplateInput $template
    ) {
    }

    /**
     * Invoke function.
     */
    public function execute(?Templates $parent = null, bool $overwrite = true): Templates
    {
        $query = [
            'apps_id' => $this->template->app->getKey(),
            'companies_id' => $this->template->company ? $this->template->company->getKey() : AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'name' => $this->template->name,
        ];

        $attributes = [
            'users_id' => $this->template->user ? $this->template->user->getKey() : AppEnums::GLOBAL_USER_ID->getValue(),
            'template' => $this->template->template,
            'parent_template_id' => $parent ? $parent->getId() : 0,
        ];

        if ($overwrite) {
            return Templates::updateOrCreate($query, $attributes);
        }

        return Templates::firstOrCreate($query, $attributes);
    }
}
