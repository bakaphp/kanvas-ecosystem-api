<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\DealerSocket;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\Enums\SalesEventTypeEnum;
use Kanvas\Connectors\DealerSocket\Enums\SalesStatusEnum;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;

class SetupCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:dealersocket:setup
                            {app_id : The application ID}
                            {company_id : Company ID to process (if not provided all companies will be processed)}
                            {user_id : User ID to process the command}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Configure DealerSocket connector for an application';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));

        //setup lead type
        $leadType = [
            SalesEventTypeEnum::NEW_VEHICLE->label() => SalesEventTypeEnum::NEW_VEHICLE->value,
            SalesEventTypeEnum::USED_VEHICLE->label() => SalesEventTypeEnum::USED_VEHICLE->value,
        ];

        foreach ($leadType as $label => $externalId) {
            LeadType::firstOrCreate(
                [
                    'apps_id' => $app->getId(),
                    'description' => (string)$externalId,
                    'companies_id' => $company->getId(),
                ],
                [
                    'name' => $label,
                    'is_active' => true,
                ]
            );
            $this->info("Lead Type {$label} created/updated");
        }

        $leadStatus = [
            SalesStatusEnum::UNQUALIFIED->label() => SalesStatusEnum::UNQUALIFIED->value,
            SalesStatusEnum::UP_CONTACTED->label() => SalesStatusEnum::UP_CONTACTED->value,
            SalesStatusEnum::STORE_VISIT->label() => SalesStatusEnum::STORE_VISIT->value,
            SalesStatusEnum::DEMO_VEHICLE->label() => SalesStatusEnum::DEMO_VEHICLE->value,
            SalesStatusEnum::WRITE_UP->label() => SalesStatusEnum::WRITE_UP->value,
            SalesStatusEnum::PENDING_FI->label() => SalesStatusEnum::PENDING_FI->value,
            SalesStatusEnum::SOLD->label() => SalesStatusEnum::SOLD->value,
            SalesStatusEnum::LOST->label() => SalesStatusEnum::LOST->value,
        ];

        foreach ($leadStatus as $label => $externalId) {
            LeadStatus::firstOrCreate(
                [
                     'name' => $label,
                 ],
            );
            $this->info("Lead Status {$label} created/updated");
        }

        $leadSource = 'DealerSocket';
        LeadSource::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'name' => $leadSource,
            ],
            [
                'description' => 'Leads coming from DealerSocket',
                'is_active' => true,
            ]
        );
        $this->info("Lead Source {$leadSource} created/updated");

        $this->info('DealerSocket setup completed successfully.');
    }
}
