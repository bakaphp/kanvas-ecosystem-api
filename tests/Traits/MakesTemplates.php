<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Enums\TemplateEngineEnum;
use Kanvas\Templates\Models\Templates;

trait MakesTemplates
{
    protected function makeTemplate(string $body, ?Companies $company = null): Templates
    {
        return new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => 'template-' . uniqid(),
                'template' => $body,
                'company' => $company,
            ])
        )->execute();
    }

    /**
     * The only way a blade row comes to exist: written by hand in the database.
     */
    protected function makeHandMadeBladeTemplate(string $body, ?Companies $company = null): Templates
    {
        $template = $this->makeTemplate('<p>placeholder</p>', $company);

        DB::connection('ecosystem')->table('email_templates')
            ->where('id', $template->getId())
            ->update(['template' => $body, 'engine' => TemplateEngineEnum::BLADE->value]);

        return $template->refresh();
    }
}
