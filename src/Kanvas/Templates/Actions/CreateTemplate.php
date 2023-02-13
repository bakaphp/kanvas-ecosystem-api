<?php

declare(strict_types=1);

namespace Kanvas\Templates\Actions;

use Kanvas\Enums\AppEnums;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Models\Templates;

class CreateTemplate
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
     *
     * @return Templates
     */
    public function execute(): Templates
    {
        $template = new Templates();
        $template->apps_id = $this->template->app->getKey();
        $template->companies_id = $this->template->company ? $this->template->company->getKey() : AppEnums::GLOBAL_COMPANY_ID->getValue();
        $template->users_id = $this->template->user ? $this->template->user->getKey() : AppEnums::GLOBAL_USER_ID->getValue();
        $template->name = $this->template->name;
        $template->template = $this->template->template;
        $template->saveOrFail();

        return $template;
    }
}
