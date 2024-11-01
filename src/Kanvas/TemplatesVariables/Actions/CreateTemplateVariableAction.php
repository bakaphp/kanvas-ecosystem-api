<?php

declare(strict_types=1);

namespace Kanvas\TemplatesVariables\Actions;

use Kanvas\Enums\AppEnums;
use Kanvas\TemplatesVariables\DataTransferObject\TemplatesVariablesDto;
use Kanvas\TemplatesVariables\Models\TemplatesVariables;

class CreateTemplateVariableAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected TemplatesVariablesDto $templateVariable
    ) {
    }

    /**
     * Invoke function.
     */
    public function execute(): TemplatesVariables
    {
        return TemplatesVariables::updateOrCreate(
            [
                'apps_id' => $this->templateVariable->app->getKey(),
                'companies_id' => $this->templateVariable->company ? $this->templateVariable->company->getKey() : AppEnums::GLOBAL_COMPANY_ID->getValue(),
                'name' => $this->templateVariable->name,
            ],
            [
                'users_id' => $this->templateVariable->user ? $this->templateVariable->user->getKey() : AppEnums::GLOBAL_USER_ID->getValue(),
                'apps_id' => $this->templateVariable->app->getKey(),
                'companies_id' => $this->templateVariable->company ? $this->templateVariable->company->getKey() : AppEnums::GLOBAL_COMPANY_ID->getValue(),
                'name' => $this->templateVariable->name,
                'value' => $this->templateVariable->value,
                'template_id' => $this->templateVariable->template_id,
            ]
        );
    }
}
