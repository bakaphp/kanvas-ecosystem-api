<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Salesforce;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Services\SalesforceApiClient;
use Kanvas\Exceptions\ValidationException;

class SalesforceSchemaQuery
{
    use ResolvesActingContext;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function objects(mixed $root, array $request): array
    {
        $client = $this->client();

        $objects = $client->describeGlobal()['sobjects'] ?? [];

        $search = strtolower((string) ($request['search'] ?? ''));
        if ($search !== '') {
            $objects = array_filter(
                $objects,
                fn (array $object): bool => str_contains(strtolower($object['name']), $search)
                    || str_contains(strtolower($object['label']), $search),
            );
        }

        return array_map(
            fn (array $object): array => [
                'name' => $object['name'],
                'label' => $object['label'],
                'custom' => (bool) $object['custom'],
            ],
            $objects,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(mixed $root, array $request): array
    {
        $client = $this->client();

        $objectName = $this->assertValidObjectName((string) $request['object_name']);
        $fields = $client->describeObject($objectName)['fields'] ?? [];

        return array_map(
            fn (array $field): array => [
                'name' => $field['name'],
                'label' => $field['label'],
                'type' => $field['type'],
                'custom' => (bool) $field['custom'],
            ],
            $fields,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function records(mixed $root, array $request): array
    {
        $client = $this->client();

        $objectName = $this->assertValidObjectName((string) $request['object_name']);
        $limit = min((int) ($request['limit'] ?? 50), 200);

        $soql = "SELECT Id, Name FROM {$objectName}";
        if (! empty($request['search'])) {
            $soql .= " WHERE Name LIKE '%" . $this->escapeSoqlLiteral((string) $request['search']) . "%'";
        }
        $soql .= " LIMIT {$limit}";

        $result = $client->query($soql);

        return array_map(
            fn (array $record): array => [
                'id' => $record['Id'],
                'name' => (string) ($record['Name'] ?? ''),
            ],
            $result['records'] ?? [],
        );
    }

    private function client(): SalesforceApiClient
    {
        $ctx = $this->actingContext();

        return Client::getInstance($ctx->app, $ctx->company);
    }

    private function assertValidObjectName(string $objectName): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $objectName)) {
            throw new ValidationException("Invalid Salesforce object name: {$objectName}");
        }

        return $objectName;
    }

    private function escapeSoqlLiteral(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
