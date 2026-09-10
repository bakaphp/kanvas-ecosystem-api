<?php

namespace Database\Seeders;

use Database\Seeders\Workflow\IntegrationsSeeder;
use Database\Seeders\Workflow\RatingWorkflowActionsSeeder;
use Database\Seeders\Workflow\RulesTypesSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            CurrencySeeder::class,
            LanguagesSeeder::class,
            AppSeeder::class,
            AppSettingsSeeder::class,
            AppPlansSeeder::class,
            CountriesSeeder::class,
            StatesSeeder::class,
            //CitiesSeeder::class,
            RolesSeeder::class,
            SourceSeeder::class,
            SystemModuleSeeder::class,
            UserSeeder::class,
            NotificationTypesSeeder::class,
            TemplateSeeder::class,
            AgentRuntimeEmailTemplateSeeder::class,
            CustomerUpdateEmailTemplateSeeder::class,
            CustomFieldsTypesSeeder::class,
            MessageActivityTypeSeeder::class,
            NotificationChannelsSeeder::class,
            KanvasModulesSeeder::class,
            SoukSeeder::class,
            IntegrationsSeeder::class,
            RulesTypesSeeder::class,
            RatingWorkflowActionsSeeder::class,
            AppPlansPricesSeeder::class,
            SourceSocialSeeder::class,
            //CanadaCitiesSeeder::class,
        ]);
    }
}
