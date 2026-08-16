<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Salesforce;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Salesforce\Actions\PullPropertyAction;
use Kanvas\Connectors\Salesforce\Actions\PullPropertyContactAction;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Services\SalesforceApiClient;

/**
 * Test-only import: pulls Location__c (+ its primary Location_Contact__c) from Salesforce into
 * Kanvas Products, using GAGroup's field names hardcoded. This is the fixture for validating the
 * Product+Attributes mapping before building the real per-tenant field mapper.
 */
class ImportSalesforcePropertiesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:salesforce-import-properties {app_id} {company_id} {--project_id=}';

    protected $description = 'Pull Location__c (+ primary Location_Contact__c) from Salesforce into Kanvas Products, for testing the Property mapping.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = $company->user;

        $client = Client::getInstance($app, $company);

        $soql = 'SELECT Id, Name, Property_Name__c, Deal_Status__c, Marketing_Status__c, Street__c, City__c, '
            . 'State_Province__c, Zip_Code__c, Brand__c, Ask_Deal_Type__c, Location_Type__c, Gross_SF__c, '
            . 'Property_Acreage__c, Year_Built__c, Zoning__c, Latitude__c, Longitude__c FROM Location__c';

        $projectId = $this->option('project_id');
        if ($projectId) {
            $soql .= " WHERE Parent_Deal__c = '" . $projectId . "'";
        }

        $records = $this->fetchAll($client, $soql);
        $this->line('Pulled ' . count($records) . ' Location__c record(s) from Salesforce.');

        $imported = 0;
        foreach ($records as $fields) {
            $product = new PullPropertyAction(
                $app,
                $company,
                $user,
                $fields,
                (string) $fields['Id'],
            )->execute();
            $this->line('  + ' . $product->name . ' (product_id=' . $product->getId() . ')');
            $imported++;

            $contactResult = $client->query(
                "SELECT Id, Contact_Name__c, Contact_Email__c, Contact_Phone__c, Company_Name__c "
                . "FROM Location_Contact__c WHERE Location__c = '" . $fields['Id'] . "' AND Primary_Location_Contact__c = true LIMIT 1"
            );

            if (! empty($contactResult['records'])) {
                $contactFields = $contactResult['records'][0];
                $broker = new PullPropertyContactAction(
                    $app,
                    $company,
                    $product,
                    $contactFields,
                    (string) $contactFields['Id'],
                )->execute();

                if ($broker) {
                    $this->line('    broker: ' . $broker->getName());
                }
            }
        }

        $this->info('Done. Imported ' . $imported . ' properties.');

        return self::SUCCESS;
    }

    private function fetchAll(SalesforceApiClient $client, string $soql): array
    {
        $result = $client->query($soql);
        $records = $result['records'] ?? [];

        while (! ($result['done'] ?? true) && ! empty($result['nextRecordsUrl'])) {
            $result = $client->queryMore($result['nextRecordsUrl']);
            $records = array_merge($records, $result['records'] ?? []);
        }

        return $records;
    }
}
