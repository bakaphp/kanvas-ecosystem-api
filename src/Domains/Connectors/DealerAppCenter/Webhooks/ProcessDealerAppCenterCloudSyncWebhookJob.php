<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerAppCenter\Webhooks;

use Kanvas\Connectors\DealerAppCenter\DataTransferObject\InventoryCsvPayload;
use Kanvas\Connectors\DealerAppCenter\Enums\ConfigurationEnum;
use Kanvas\Connectors\DealerAppCenter\Services\CloudSyncResponseMerger;
use Kanvas\Connectors\DealerAppCenter\Services\InventoryCsvParser;
use Kanvas\Connectors\DealerAppCenter\Services\InventorySftpClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use Throwable;

/**
 * Cloud-sync webhook receiver — replaces the legacy `inventorycatcher/cloudsync`
 * upstream for dealer-api's `InventoryController::download()`.
 *
 * Request body:
 *   {
 *     "key": "<shared secret>",
 *     "id":  "<shared identifier>",
 *     "rooftop_ids": ["aisj", "caggm"]
 *   }
 *
 * Response body (HTTP 200 unless infra failure):
 *   {
 *     "status": "success" | "error",
 *     "message": "...",
 *     "data": {
 *       "fileHeaders":  [...],
 *       "fileFirstRow": [...],
 *       "fileValues":   [[...], [...]]
 *     } | null
 *   }
 *
 * Config (app-level, single JSON blob under `dealer_app_center_cloud_sync`):
 *   {
 *     "key": "...",
 *     "id":  "...",
 *     "ftps": [
 *       { "host": "...", "username": "...", "password": "...", "port": 22 }
 *     ]
 *   }
 */
class ProcessDealerAppCenterCloudSyncWebhookJob extends ProcessWebhookJob
{
    /**
     * Filename conventions on the upstream FTP, tried in order.
     * The screenshot of the live FTP shows both `{shortname}_inventory.csv`
     * (35/37 files) and bare `{shortname}.csv` (2/37 files).
     */
    private const array FILENAME_PATTERNS = [
        '%s_inventory.csv',
        '%s.csv',
    ];

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload ?? [];
        $app = $this->receiver->app;

        $config = $this->loadConfig($app);
        if ($config === null) {
            return $this->errorResponse('Cloud sync is not configured');
        }

        if (! $this->authenticate($payload, $config)) {
            return $this->errorResponse('Unauthorized');
        }

        $rooftopIds = $this->extractRooftopIds($payload);
        if ($rooftopIds === []) {
            return $this->errorResponse('No rooftop IDs provided');
        }

        $ftps = $config['ftps'];
        if ($ftps === []) {
            return $this->errorResponse('No FTP sources configured');
        }

        try {
            $payloads = $this->collectPayloads($rooftopIds, $ftps);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->getMessage());
        } catch (Throwable $e) {
            // Unexpected. Surface as a business-logic error so dealer-api
            // shows the message; the underlying exception is captured by the
            // parent handle() via Sentry.
            return $this->errorResponse('Cloud sync failed: ' . $e->getMessage());
        }

        $data = new CloudSyncResponseMerger()->merge($payloads);

        return [
            'status' => 'success',
            'message' => sprintf(
                'Inventory loaded for %s.',
                implode(', ', $rooftopIds),
            ),
            'data' => $data,
        ];
    }

    /**
     * @return array{key:string,id:string,ftps:list<array<array-key,mixed>>}|null
     */
    protected function loadConfig(mixed $app): ?array
    {
        $raw = $app->get(ConfigurationEnum::CLOUD_SYNC->value);

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($raw)) {
            return null;
        }

        $key = isset($raw['key']) ? (string) $raw['key'] : '';
        $id = isset($raw['id']) ? (string) $raw['id'] : '';

        $ftps = [];
        if (isset($raw['ftps']) && is_array($raw['ftps'])) {
            foreach ($raw['ftps'] as $entry) {
                if (is_array($entry)) {
                    $ftps[] = $entry;
                }
            }
        }

        if ($key === '' || $id === '') {
            return null;
        }

        return ['key' => $key, 'id' => $id, 'ftps' => $ftps];
    }

    /**
     * @param array<array-key,mixed>                                       $payload
     * @param array{key:string,id:string,ftps:list<array<array-key,mixed>>} $config
     */
    private function authenticate(array $payload, array $config): bool
    {
        $key = isset($payload['key']) ? (string) $payload['key'] : '';
        $id = isset($payload['id']) ? (string) $payload['id'] : '';

        if ($key === '' || $id === '') {
            return false;
        }

        return hash_equals($config['key'], $key)
            && hash_equals($config['id'], $id);
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return list<string>
     */
    private function extractRooftopIds(array $payload): array
    {
        $raw = $payload['rooftop_ids'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            $clean = strtolower(trim((string) $value));
            if ($clean !== '') {
                $ids[] = $clean;
            }
        }

        return $ids;
    }

    /**
     * Walk each rooftop, find its file across the configured FTPs, parse it.
     *
     * Listings are cached per FTP per request so a 10-rooftop request issues
     * at most one `nlist()` per FTP, not one per rooftop.
     *
     * @param list<string>                       $rooftopIds
     * @param list<array<array-key,mixed>>        $ftps
     *
     * @return list<InventoryCsvPayload>
     *
     * @throws ValidationException
     */
    private function collectPayloads(array $rooftopIds, array $ftps): array
    {
        /** @var array<int,array{client:InventorySftpClient,files:list<string>}> $opened */
        $opened = [];
        $payloads = [];

        try {
            foreach ($rooftopIds as $rooftopId) {
                $payloads[] = $this->fetchOne($rooftopId, $ftps, $opened);
            }
        } finally {
            foreach ($opened as $entry) {
                try {
                    $entry['client']->disconnect();
                } catch (Throwable) {
                    // best-effort cleanup
                }
            }
        }

        return $payloads;
    }

    /**
     * @param list<array<array-key,mixed>>                                    $ftps
     * @param array<int,array{client:InventorySftpClient,files:list<string>}> $opened
     *
     * @throws ValidationException
     */
    private function fetchOne(string $rooftopId, array $ftps, array &$opened): InventoryCsvPayload
    {
        foreach ($ftps as $index => $ftpConfig) {
            if (! isset($opened[$index])) {
                $client = $this->makeSftpClient($this->normalizeFtpConfig($ftpConfig));
                $opened[$index] = [
                    'client' => $client,
                    'files' => $client->listRoot(),
                ];
            }

            $filename = $this->matchFilename($rooftopId, $opened[$index]['files']);
            if ($filename === null) {
                continue;
            }

            $contents = $opened[$index]['client']->read($filename);
            if ($contents === null || trim($contents) === '') {
                throw new ValidationException(sprintf(
                    'Inventory file for %s is empty or invalid',
                    $rooftopId,
                ));
            }

            $payload = new InventoryCsvParser()->parse($rooftopId, $contents);
            if ($payload->isEmpty()) {
                throw new ValidationException(sprintf(
                    'Inventory file for %s is empty or invalid',
                    $rooftopId,
                ));
            }

            return $payload;
        }

        throw new ValidationException(sprintf(
            'No inventory file found for rooftop %s',
            $rooftopId,
        ));
    }

    /**
     * @param array<array-key,mixed> $config
     *
     * @return array{host:string,username:string,password:string,port?:int}
     */
    private function normalizeFtpConfig(array $config): array
    {
        $normalized = [
            'host' => (string) ($config['host'] ?? ''),
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
        ];

        if (isset($config['port'])) {
            $normalized['port'] = (int) $config['port'];
        }

        return $normalized;
    }

    /**
     * @param list<string> $files
     */
    private function matchFilename(string $rooftopId, array $files): ?string
    {
        // Build a case-insensitive index of available filenames once per FTP.
        $lookup = [];
        foreach ($files as $name) {
            $lookup[strtolower($name)] = $name;
        }

        foreach (self::FILENAME_PATTERNS as $pattern) {
            $candidate = strtolower(sprintf($pattern, $rooftopId));
            if (isset($lookup[$candidate])) {
                return $lookup[$candidate];
            }
        }

        return null;
    }

    /**
     * Seam for tests — swap in a fake SFTP client without touching the network.
     *
     * @param array{host:string,username:string,password:string,port?:int} $config
     */
    protected function makeSftpClient(array $config): InventorySftpClient
    {
        return new InventorySftpClient($config);
    }

    /**
     * @return array{status:string,message:string,data:null}
     */
    private function errorResponse(string $message): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'data' => null,
        ];
    }
}
