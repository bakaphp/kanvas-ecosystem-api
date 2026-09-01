<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Salesforce;

use Baka\Traits\KanvasJobsTrait;
use Bouncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Salesforce\Actions\PullPropertyAction;
use Kanvas\Connectors\Salesforce\Actions\PullPropertyContactAction;
use Kanvas\Connectors\Salesforce\Actions\PullPropertyFilesAction;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Services\SalesforceApiClient;
use RuntimeException;
use Throwable;

/**
 * Test-only import: pulls Location__c (+ its primary Location_Contact__c and any Salesforce Files
 * attached to it) from Salesforce into Kanvas Products, using GAGroup's field names hardcoded
 * (`PullPropertyAction::mapAttributes()`). Deliberately unfiltered — imports every Location__c
 * regardless of Deal_Status__c/Marketing_Status__c, saving both as attributes; the website's
 * "which properties are listed" question is answered downstream with Kanvas's own product/attribute
 * filtering (`hasAttributeValues`/`variantAttributeValue`), not by narrowing the SOQL at import time.
 *
 * The per-record upsert runs inside a queued closure (not a dedicated Job class) so this
 * GAGroup-only fixture doesn't grow a permanent class in the shared Salesforce connector — the
 * command stays the only place that knows about Location__c/Location_Contact__c.
 */
class ImportSalesforcePropertiesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:salesforce-import-properties {app_id} {company_id} {--project_id=} {--limit=}';

    protected $description = 'Pull Location__c (+ primary Location_Contact__c) from Salesforce and queue them for import into Kanvas Products, for testing the Property mapping.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = $company->user;

        $client = Client::getInstance($app, $company);

        $soql = 'SELECT Id, Name, Property_Name__c, Deal_Status__c, Marketing_Status__c, Store__c, Street__c, City__c, '
            . 'State_Province__c, Zip_Code__c, Brand__c, Ask_Deal_Type__c, Location_Type__c, Gross_SF__c, '
            . 'Property_Acreage__c, Year_Built__c, Zoning__c, Latitude__c, Longitude__c FROM Location__c';

        $projectId = $this->option('project_id');
        if ($projectId) {
            $soql .= " WHERE Parent_Deal__c = '" . $projectId . "'";
        }

        $soql .= ' ORDER BY CreatedDate DESC';

        $limit = $this->option('limit');
        if ($limit) {
            $soql .= ' LIMIT ' . (int) $limit;
        }

        $properties = $this->fetchAll($client, $soql);
        $this->line('Pulled ' . count($properties) . ' Location__c record(s) from Salesforce.');

        if ($properties === []) {
            $this->info('Nothing to import.');

            return self::SUCCESS;
        }

        // One bulk query for every primary broker instead of one query per property — with
        // thousands of properties, a per-record contact lookup would multiply the Salesforce API
        // calls by the same amount.
        $contacts = $this->fetchAll(
            $client,
            'SELECT Id, Location__c, Contact_Name__c, Contact_Email__c, Contact_Phone__c, Contact_Mobile__c, Company_Name__c '
                . 'FROM Location_Contact__c WHERE Primary_Location_Contact__c = true',
        );

        dispatch(function () use ($app, $company, $user, $properties, $contacts): void {
            App::scoped(Apps::class, fn () => $app);
            Bouncer::scope()->to(RolesEnums::getScope($app));

            $contactsByLocationId = [];
            foreach ($contacts as $contact) {
                $locationId = (string) ($contact['Location__c'] ?? '');
                if ($locationId !== '' && ! isset($contactsByLocationId[$locationId])) {
                    $contactsByLocationId[$locationId] = $contact;
                }
            }

            // Resolved once and reused per property — Client::getInstance() caches the access
            // token itself, so this is cheap even across a long-running queued closure.
            $client = Client::getInstance($app, $company);

            $total = count($properties);
            $processed = 0;
            $failed = 0;

            foreach ($properties as $index => $property) {
                try {
                    $salesforceId = (string) ($property['Id'] ?? '');
                    if ($salesforceId === '') {
                        throw new RuntimeException('Salesforce record is missing an Id');
                    }

                    $product = new PullPropertyAction(
                        $app,
                        $company,
                        $user,
                        $property,
                        $salesforceId,
                    )->execute();

                    $contact = $contactsByLocationId[$salesforceId] ?? null;
                    if ($contact !== null) {
                        new PullPropertyContactAction(
                            $app,
                            $company,
                            $product,
                            $contact,
                            (string) $contact['Id'],
                        )->execute();
                    }

                    new PullPropertyFilesAction(
                        $app,
                        $company,
                        $product,
                        $user,
                        $client,
                        $salesforceId,
                    )->execute();

                    $processed++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('Salesforce property import failed for one record', [
                        'salesforce_id' => $property['Id'] ?? null,
                        'name' => $property['Name'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                    report($e);
                }

                if (($index + 1) % 100 === 0 || $index + 1 === $total) {
                    Log::info('Salesforce properties import progress', [
                        'companies_id' => $company->getId(),
                        'done' => $index + 1,
                        'total' => $total,
                        'processed' => $processed,
                        'failed' => $failed,
                    ]);
                }
            }
        });

        $this->info('Queued ' . count($properties) . ' properties (+ ' . count($contacts) . ' brokers) for background import.');

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
