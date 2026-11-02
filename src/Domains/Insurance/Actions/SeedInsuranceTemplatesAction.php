<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Insurance\Services\InsuranceCarnet;
use Kanvas\Insurance\Services\InsurancePolicyReminders;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Models\Templates;

class SeedInsuranceTemplatesAction
{
    private const array SUBJECTS = [
        InsuranceCarnet::TEMPLATE => null,
        InsuranceCarnet::EMAIL_TEMPLATE => 'Tu póliza está activa - carnet digital adjunto',
        InsurancePolicyReminders::TEMPLATE => 'Tu póliza {% if daysLeft <= 0 %}vence hoy{% elseif daysLeft == 1 %}vence mañana{% else %}vence en {{ daysLeft }} días{% endif %}',
        InsurancePolicyReminders::PUSH_TITLE_TEMPLATE => null,
        InsurancePolicyReminders::PUSH_MESSAGE_TEMPLATE => null,
    ];

    public function __construct(
        private readonly Apps $app,
    ) {
    }

    public function execute(): void
    {
        foreach (self::SUBJECTS as $name => $subject) {
            $exists = Templates::query()
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                ->where('name', $name)
                ->notDeleted()
                ->exists();

            if ($exists) {
                continue;
            }

            new CreateTemplateAction(
                new TemplateInput(
                    app: $this->app,
                    name: $name,
                    template: (string) file_get_contents(resource_path('insurance/templates/' . $name . '.html')),
                    subject: $subject,
                    title: $name,
                    isSystem: true,
                )
            )->execute(overwrite: false);
        }
    }
}
