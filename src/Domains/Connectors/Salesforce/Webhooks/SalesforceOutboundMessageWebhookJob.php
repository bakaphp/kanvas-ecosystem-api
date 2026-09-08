<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Webhooks;

use Kanvas\Connectors\Salesforce\Actions\PullDealAction;
use Kanvas\Connectors\Salesforce\Actions\PullLeadAction;
use Kanvas\Connectors\Salesforce\Actions\PullOrganizationAction;
use Kanvas\Connectors\Salesforce\Actions\PullPeopleAction;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Actions\ApplyFilesystemMapperAction;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use SimpleXMLElement;
use Throwable;

/**
 * One job handles all four Salesforce objects — Salesforce Outbound Messages are configured per
 * object in Salesforce Setup (one Workflow Rule each), so there are four `ReceiverWebhook` rows
 * pointing at this same job, each carrying `configuration['salesforce_object']` to say which one.
 *
 * The Ack Salesforce needs (`<notificationsResponse><Ack>true</Ack></notificationsResponse>`) is
 * answered by `ReceiverController` via each receiver's `configuration['async_response']` — no
 * response-shaping code needed here.
 */
#[WorkflowAction]
class SalesforceOutboundMessageWebhookJob extends ProcessWebhookJob
{
    private const string SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const string OUTBOUND_NS = 'http://soap.sforce.com/2005/09/outbound';
    private const string SOBJECT_NS = 'urn:sobject.enterprise.soap.sforce.com';

    #[Override]
    public function execute(): array
    {
        $raw = (string) $this->webhookRequest->raw_payload;

        // Malformed XML makes simplexml_load_string emit a PHP warning on top of returning false —
        // left unsuppressed, Laravel's error handler escalates that warning into a thrown
        // ErrorException before the false-check below ever runs. libxml_use_internal_errors(true)
        // routes parse problems into libxml's own error queue instead, so `=== false` is reachable.
        $previousXmlErrorSetting = libxml_use_internal_errors(true);

        // XXE guard: LIBXML_NONET blocks network entity resolution and no DTD/entity-loading flag
        // is passed, so a crafted external entity in this third-party XML never gets expanded.
        $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousXmlErrorSetting);

        if ($xml === false) {
            return ['message' => 'Unable to parse Salesforce Outbound Message XML'];
        }

        $xml->registerXPathNamespace('soapenv', self::SOAP_NS);
        $xml->registerXPathNamespace('outbound', self::OUTBOUND_NS);

        $salesforceObject = (string) ($this->receiver->configuration['salesforce_object'] ?? '');
        $notifications = $xml->xpath('//outbound:Notification') ?: [];

        $results = [];
        foreach ($notifications as $notification) {
            $fields = $this->extractFields($notification);
            $salesforceId = $fields['Id'] ?? null;
            unset($fields['Id']);

            if ($salesforceId === null || $salesforceId === '') {
                continue;
            }

            try {
                $results[] = $this->dispatchToAction($salesforceObject, $fields, $salesforceId);
            } catch (Throwable $e) {
                report($e);
                $results[] = [
                    'salesforce_id' => $salesforceId,
                    'processed' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'message' => 'Salesforce Outbound Message processed',
            'salesforce_object' => $salesforceObject,
            'processed' => count($results),
            'results' => $results,
        ];
    }

    private function extractFields(SimpleXMLElement $notification): array
    {
        // Each SimpleXMLElement returned by ->xpath() is a fresh object that does not inherit the
        // parent's registered prefixes, so the "outbound" mapping has to be registered again here.
        $notification->registerXPathNamespace('outbound', self::OUTBOUND_NS);
        $sObjectNodes = $notification->xpath('outbound:sObject');

        if (empty($sObjectNodes)) {
            return [];
        }

        $fields = [];
        foreach ($sObjectNodes[0]->children(self::SOBJECT_NS) as $field) {
            $fields[$field->getName()] = trim((string) $field);
        }

        return $fields;
    }

    private function dispatchToAction(string $salesforceObject, array $fields, string $salesforceId): array
    {
        $app = $this->receiver->app;
        $company = $this->receiver->company;

        $entity = match ($salesforceObject) {
            'Lead' => new PullLeadAction($app, $company, $fields, $salesforceId)->execute(),
            'Contact' => new PullPeopleAction($app, $company, $fields, $salesforceId)->execute(),
            'Account' => new PullOrganizationAction($app, $company, $fields, $salesforceId)->execute(),
            'Opportunity' => new PullDealAction($app, $company, $fields, $salesforceId)->execute(),
            default => $this->applyMapper($fields, $salesforceId),
        };

        return [
            'salesforce_id' => $salesforceId,
            'entity_id' => $entity?->getId(),
            'processed' => $entity !== null,
        ];
    }

    /**
     * Handles any Salesforce object that isn't one of the four standard ones above — custom
     * objects are tenant-specific, so there's no name to match on here. The receiver instead
     * carries `configuration['mapper_id']`, pointing at a `FilesystemMapper` that already knows
     * how to turn this object's fields into a Kanvas entity (and, via `configuration.links` on
     * that mapper, how to find and create any related entity too).
     */
    private function applyMapper(array $fields, string $salesforceId): Products|People|null
    {
        $mapperId = (int) ($this->receiver->configuration['mapper_id'] ?? 0);
        if ($mapperId === 0) {
            return null;
        }

        $app = $this->receiver->app;
        $company = $this->receiver->company;
        $mapper = FilesystemMapper::getByIdFromCompanyApp($mapperId, $company, $app);

        return new ApplyFilesystemMapperAction(
            $app,
            $company,
            $company->user,
            $mapper,
            $salesforceId,
            $fields,
            [],
            function (string $sourceObject, string $matchField, string $primaryId) use ($app, $company): ?array {
                $sourceObject = $this->assertValidSoqlIdentifier($sourceObject);
                $matchField = $this->assertValidSoqlIdentifier($matchField);
                $escapedId = $this->escapeSoqlLiteral($primaryId);

                $soql = "SELECT FIELDS(ALL) FROM {$sourceObject} WHERE {$matchField} = '{$escapedId}' LIMIT 1";

                return Client::getInstance($app, $company)->query($soql)['records'][0] ?? null;
            },
        )->execute();
    }

    private function assertValidSoqlIdentifier(string $identifier): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new ValidationException("Invalid Salesforce object/field name: {$identifier}");
        }

        return $identifier;
    }

    private function escapeSoqlLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
