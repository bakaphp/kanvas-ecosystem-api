<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Salesforce;

use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Bouncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Salesforce\Actions\PullProjectAction;
use Kanvas\Connectors\Salesforce\Actions\PullProjectContactAction;
use Kanvas\Connectors\Salesforce\Actions\PullPropertyAction;
use Kanvas\Connectors\Salesforce\Actions\PullPropertyContactAction;
use Kanvas\Connectors\Salesforce\Actions\PullPropertyFilesAction;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Services\SalesforceApiClient;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Throwable;

/**
 * Test-only import: pulls Project__c (+ its Location__c children, every Location_Contact__c and
 * Project_Contact__c on them, and any Salesforce Files attached to each Location__c) from Salesforce
 * into Kanvas — one Product per Project__c, one Variant per Location__c. Location__c.Parent_Deal__c
 * is a single required lookup to Project__c, the same shape as products_variants.products_id, which
 * is why a Project maps to a Product and its Locations map to that Product's Variants rather than
 * each Location becoming its own Product (GAGroup field names hardcoded via
 * `PullPropertyAction::attributesFromPayload()`). Deliberately unfiltered — imports every
 * Location__c regardless of Deal_Status__c/Marketing_Status__c, saving both as variant attributes;
 * the website's "which properties are listed" question is answered downstream with Kanvas's own
 * product/attribute filtering (`hasAttributeValues`/`variantAttributeValue`), not by narrowing the
 * SOQL at import time.
 *
 * Every Location_Contact__c and Project_Contact__c row becomes its own People — Kanvas has no
 * dedicated People<->Product/Variant relation, so PullPropertyContactAction/PullProjectContactAction
 * each encode the relationship (and Location_Contact__c's Interest_Level__c, standing in for a
 * lead-variant-interest record) as a tag instead; see those classes' docblocks.
 *
 * The orchestration (grouping locations by project, batching, progress logging) runs inside a
 * queued closure rather than a dedicated Job class, since it's specific to this bulk-import shape;
 * the per-object field mapping it calls into (PullProjectAction, PullPropertyAction and friends)
 * are ordinary connector Actions, reusable outside this command.
 */
class ImportSalesforcePropertiesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:salesforce-import-properties {app_id} {company_id} {--project_id=} {--limit=}';

    protected $description = 'Pull Project__c (+ Location__c children, every Location_Contact__c and Project_Contact__c) from Salesforce and queue them for import into Kanvas Products/Variants/People, for testing the Project/Location mapping.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = $company->user;

        $client = Client::getInstance($app, $company);

        $locationSoql = 'SELECT Id, Name, Parent_Deal__c, Property_Name__c, Deal_Status__c, Marketing_Status__c, '
            . 'Store__c, Street__c, City__c, State_Province__c, Zip_Code__c, Brand__c, Ask_Deal_Type__c, '
            . 'Location_Type__c, Gross_SF__c, Property_Acreage__c, Year_Built__c, Zoning__c, Latitude__c, '
            . 'Longitude__c FROM Location__c';

        $projectId = $this->option('project_id');
        if ($projectId) {
            $locationSoql .= " WHERE Parent_Deal__c = '" . $projectId . "'";
        }

        $locationSoql .= ' ORDER BY CreatedDate DESC';

        $limit = $this->option('limit');
        if ($limit) {
            $locationSoql .= ' LIMIT ' . (int) $limit;
        }

        $locations = $this->fetchAll($client, $locationSoql);
        $this->line('Pulled ' . count($locations) . ' Location__c record(s) from Salesforce.');

        if ($locations === []) {
            $this->info('Nothing to import.');

            return self::SUCCESS;
        }

        $projectIds = array_values(array_unique(array_filter(
            array_map(fn (array $location) => (string) ($location['Parent_Deal__c'] ?? ''), $locations),
        )));

        $projects = $projectIds === []
            ? []
            : $this->fetchAll(
                $client,
                "SELECT Id, Name FROM Project__c WHERE Id IN ('" . implode("','", $projectIds) . "')",
            );

        $locationIds = array_values(array_unique(array_filter(
            array_map(fn (array $location) => (string) ($location['Id'] ?? ''), $locations),
        )));

        // One bulk query for every location contact instead of one query per location — with
        // thousands of locations, a per-record contact lookup would multiply the Salesforce API
        // calls by the same amount. Every Location_Contact__c row is imported now, not just the
        // primary one (broker, attorney, landlord, the interested party itself all get a People).
        // Scoped to the locations actually pulled above, so --project_id/--limit narrow this fetch
        // too instead of always pulling every Location_Contact__c in the org. Chunked via
        // fetchAllByIds() — an unscoped run's location count (seen at 10,000 on a real org) makes a
        // single IN (...) too long for the HTTP client to send at all.
        $contacts = $this->fetchAllByIds(
            $client,
            'SELECT Id, Location__c, Contact_Name__c, Contact_Email__c, Contact_Phone__c, Contact_Mobile__c, '
                . 'Company_Name__c, Location_Contact_Type__c, Interest_Level__c, Primary_Location_Contact__c '
                . 'FROM Location_Contact__c',
            'Location__c',
            $locationIds,
        );

        // Same bulk-fetch reasoning for Project__c's own contacts (attorneys, lenders, the deal's
        // iBanker...) — a separate object from Location_Contact__c, scoped to the projects in this
        // batch. Project_Contact__c is GAGroup-specific like the rest of this command, but not every
        // org that has Project__c/Location__c necessarily has it deployed too (seen on a test org) —
        // its absence shouldn't abort importing projects/locations/location-contacts, so this is the
        // one fetch in this command allowed to fail soft.
        $projectContacts = [];
        if ($projectIds !== []) {
            try {
                $projectContacts = $this->fetchAllByIds(
                    $client,
                    'SELECT Id, Project__c, First_Name__c, Last_Name__c, Email__c, Phone__c, Mobile__c, '
                        . 'Company_Name__c, Relationship__c FROM Project_Contact__c',
                    'Project__c',
                    $projectIds,
                );
            } catch (Throwable $e) {
                Log::warning('Salesforce Project_Contact__c fetch failed — continuing without project contacts', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        dispatch(function () use ($app, $company, $user, $projects, $locations, $contacts, $projectContacts): void {
            App::scoped(Apps::class, fn () => $app);
            Bouncer::scope()->to(RolesEnums::getScope($app));

            $contactsByLocationId = [];
            foreach ($contacts as $contact) {
                $locationId = (string) ($contact['Location__c'] ?? '');
                if ($locationId !== '') {
                    $contactsByLocationId[$locationId][] = $contact;
                }
            }

            $projectContactsByProjectId = [];
            foreach ($projectContacts as $contact) {
                $projectId = (string) ($contact['Project__c'] ?? '');
                if ($projectId !== '') {
                    $projectContactsByProjectId[$projectId][] = $contact;
                }
            }

            $locationsByProjectId = [];
            foreach ($locations as $location) {
                $projectSalesforceId = (string) ($location['Parent_Deal__c'] ?? '');
                if ($projectSalesforceId === '') {
                    continue;
                }
                $locationsByProjectId[$projectSalesforceId][] = $location;
            }

            // Resolved once and reused per project — Client::getInstance() caches the access
            // token itself, so this is cheap even across a long-running queued closure.
            $client = Client::getInstance($app, $company);

            $totalLocations = count($locations);
            $processed = 0;
            $failed = 0;
            $done = 0;

            foreach ($projects as $project) {
                $projectSalesforceId = (string) ($project['Id'] ?? '');
                $projectLocations = $locationsByProjectId[$projectSalesforceId] ?? [];

                if ($projectSalesforceId === '' || $projectLocations === []) {
                    continue;
                }

                $previousDone = $done;

                try {
                    self::importProject(
                        $app,
                        $company,
                        $user,
                        $client,
                        $project,
                        $projectSalesforceId,
                        $projectLocations,
                        $contactsByLocationId,
                        $projectContactsByProjectId[$projectSalesforceId] ?? [],
                    );
                    $processed += count($projectLocations);
                } catch (Throwable $e) {
                    $failed += count($projectLocations);
                    Log::error('Salesforce project import failed', [
                        'salesforce_id' => $projectSalesforceId,
                        'name' => $project['Name'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                    report($e);
                }

                $done += count($projectLocations);

                // Projects are batches of varying size, so a per-record `% 100 === 0` check could
                // skip past 100 entirely; comparing the before/after multiple of 100 can't miss it.
                if (intdiv($previousDone, 100) !== intdiv($done, 100) || $done === $totalLocations) {
                    Log::info('Salesforce properties import progress', [
                        'companies_id' => $company->getId(),
                        'done' => $done,
                        'total' => $totalLocations,
                        'processed' => $processed,
                        'failed' => $failed,
                    ]);
                }
            }
        });

        $this->info(
            'Queued ' . count($projects) . ' project(s) / ' . count($locations) . ' location(s) (+ '
                . count($contacts) . ' location contact(s), ' . count($projectContacts)
                . ' project contact(s)) for background import.',
        );

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $project
     * @param array<int, array<string, mixed>> $projectLocations
     * @param array<string, array<int, array<string, mixed>>> $contactsByLocationId
     * @param array<int, array<string, mixed>> $projectContacts
     */
    private static function importProject(
        Apps $app,
        Companies $company,
        UserInterface $user,
        SalesforceApiClient $client,
        array $project,
        string $projectSalesforceId,
        array $projectLocations,
        array $contactsByLocationId,
        array $projectContacts,
    ): Products {
        $variantsByLocationSku = [];
        foreach ($projectLocations as $location) {
            $locationSalesforceId = (string) ($location['Id'] ?? '');
            if ($locationSalesforceId === '') {
                continue;
            }

            $sku = 'sf-location-' . $locationSalesforceId;
            $variantsByLocationSku[$sku] = $location;
        }

        $variants = [];
        foreach ($variantsByLocationSku as $sku => $location) {
            $variants[] = [
                'name' => (string) ($location['Property_Name__c'] ?? $location['Name'] ?? 'Unknown Property'),
                'description' => (string) ($location['Brand__c'] ?? ''),
                'sku' => $sku,
                'attributes' => PullPropertyAction::attributesFromPayload($location),
            ];
        }

        $product = new PullProjectAction(
            $app,
            $company,
            $user,
            $project,
            $projectSalesforceId,
        )->execute($variants);

        foreach ($projectContacts as $projectContact) {
            new PullProjectContactAction(
                $app,
                $company,
                $product,
                $projectContact,
                (string) $projectContact['Id'],
            )->execute();
        }

        $variantModels = Variants::where('products_id', $product->getId())
            ->whereIn('sku', array_keys($variantsByLocationSku))
            ->get()
            ->keyBy('sku');

        foreach ($variantsByLocationSku as $sku => $location) {
            /** @var Variants|null $variant */
            $variant = $variantModels->get($sku);
            if ($variant === null) {
                continue;
            }

            $locationSalesforceId = (string) ($location['Id'] ?? '');

            foreach ($contactsByLocationId[$locationSalesforceId] ?? [] as $contact) {
                new PullPropertyContactAction(
                    $app,
                    $company,
                    $variant,
                    $contact,
                    (string) $contact['Id'],
                )->execute();
            }

            new PullPropertyFilesAction(
                $app,
                $company,
                $variant,
                $user,
                $client,
                $locationSalesforceId,
            )->execute();
        }

        return $product;
    }

    /**
     * Chunks $ids into IN (...) clauses no single HTTP request can choke on — a real org had
     * 10,000 Location__c rows, and one IN (...) listing all of them built a ~210,000-character
     * query string the HTTP client couldn't even send (cURL SSL_write "bad length"), well past
     * Salesforce's own 100,000-character SOQL limit too. 200 keeps each request far under both.
     *
     * @param list<string> $ids
     */
    private function fetchAllByIds(
        SalesforceApiClient $client,
        string $selectWithoutWhere,
        string $filterField,
        array $ids,
    ): array {
        $records = [];

        foreach (array_chunk($ids, 200) as $chunk) {
            $records = array_merge(
                $records,
                $this->fetchAll($client, $selectWithoutWhere . " WHERE {$filterField} IN ('" . implode("','", $chunk) . "')"),
            );
        }

        return $records;
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
