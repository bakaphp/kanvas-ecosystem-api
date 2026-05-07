<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Closure;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use NetSuite\NetSuiteService;
use SoapFault;
use Throwable;

class Client
{
    protected string $apiUrl;
    protected string $endPoint = '2021_1';
    protected ?NetSuiteService $service = null;

    // Max concurrent requests (NetSuite limit is 4, default 2 to leave room for other integrations)
    protected int $maxConcurrentRequests;
    protected int $lockTimeoutSeconds = 120;
    protected int $maxRetries = 5;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->apiUrl = $app->get(ConfigurationEnum::NET_SUITE_CUSTOM_API_URL->value) ?? 'https://webservices.netsuite.com';
        $this->maxConcurrentRequests = (int) ($app->get(ConfigurationEnum::NET_SUITE_MAX_CONCURRENT_REQUESTS->value) ?? 2);
    }

    public function getService(): NetSuiteService
    {
        if ($this->service !== null) {
            return $this->service;
        }

        $config = $this->app->get(ConfigurationEnum::NET_SUITE_ACCOUNT_CONFIG->value);

        if (empty($config)) {
            throw new ValidationException('NetSuite configuration is missing.');
        }

        $config = [
            // required -------------------------------------
            'endpoint' => $this->endPoint,
            'host' => $this->apiUrl,
            'account' => $config['account'],
            'consumerKey' => $config['consumerKey'],
            'consumerSecret' => $config['consumerSecret'],
            'token' => $config['token'],
            'tokenSecret' => $config['tokenSecret'],
            // optional -------------------------------------
            'signatureAlgorithm' => 'sha256', // Defaults to 'sha256'
            'logging' => config('app.debug', false), // Only enable logging if in debug mode
            'log_path' => storage_path('logs/netsuite.log'),
            'log_format' => 'netsuite-php-%date-%operation',
            'log_dateformat' => 'Ymd.His.u',
        ];

        $this->service = new NetSuiteService($config);

        return $this->service;
    }

    /**
     * Execute a NetSuite operation with rate limiting using Redis semaphore.
     * This ensures we never exceed the concurrent request limit (max 4 in NetSuite).
     */
    public function executeWithRateLimit(Closure $operation): mixed
    {
        $lockKey = $this->getLockKey();
        $attempt = 0;

        while ($attempt < $this->maxRetries) {
            // Try to acquire one of the available slots
            for ($slot = 0; $slot < $this->maxConcurrentRequests; $slot++) {
                $slotKey = "{$lockKey}:slot:{$slot}";
                $lock = Cache::lock($slotKey, $this->lockTimeoutSeconds);

                if ($lock->get()) {
                    try {
                        $result = $operation($this->getService());
                        $lock->forceRelease();

                        return $result;
                    } catch (SoapFault $e) {
                        $lock->forceRelease();

                        if ($this->isRateLimitError($e)) {
                            $attempt++;
                            // Exponential backoff: 2s, 4s, 8s
                            sleep(pow(2, $attempt));

                            continue 2; // Break out and retry the while loop
                        }

                        throw $e;
                    } catch (Throwable $e) {
                        $lock->forceRelease();

                        throw $e;
                    }
                }
            }

            // No slot available, wait and retry
            $attempt++;
            // Shorter wait: 1s, 2s, 3s
            sleep($attempt);
        }

        throw new ValidationException(
            "NetSuite rate limit: Could not acquire API slot after {$this->maxRetries} attempts. Check for stale locks in Redis with key: {$lockKey}"
        );
    }

    protected function getLockKey(): string
    {
        // Use NetSuite account ID as lock key so all apps sharing the same NS account share the lock pool
        $config = $this->app->get(ConfigurationEnum::NET_SUITE_ACCOUNT_CONFIG->value);
        $accountId = $config['account'] ?? $this->app->getId();

        return 'netsuite_rate_limit:' . $accountId;
    }

    protected function isRateLimitError(SoapFault $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'concurrent request limit exceeded')
            || str_contains($message, 'request blocked')
            || str_contains($message, 'suitetalk concurrent request limit');
    }
}
