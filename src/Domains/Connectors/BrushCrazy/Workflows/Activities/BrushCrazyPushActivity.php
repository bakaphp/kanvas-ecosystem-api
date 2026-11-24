<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Baka\Http\Exceptions\SsrfException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Support\BrushCrazyApi;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Shared transport for the Kanvas → BrushCrazy write-back. BrushCrazy answers 401 (bad signature)
 * and 422 (validation) for requests a retry cannot fix, so any 4xx ends the run as FAILED without
 * a Sentry report; a 5xx or a timeout is thrown so the queued activity is retried. Every endpoint
 * is idempotent on the Kanvas uuid it receives, which is what makes those retries safe.
 */
abstract class BrushCrazyPushActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 5;

    protected function runPush(
        Model $entity,
        AppInterface $app,
        Companies $company,
        array $params,
        Closure $operation
    ): array {
        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::BRUSHCRAZY,
            integrationOperation: $operation,
            additionalParams: $params,
            company: $company,
            throwException: true,
        );
    }

    /**
     * @param Closure(array): array $onSuccess receives BrushCrazy's decoded response
     */
    protected function post(
        AppInterface $app,
        string $path,
        array $body,
        Closure $onSuccess
    ): array {
        $api = BrushCrazyApi::forApp($app);

        if ($api === null) {
            return $this->failWorkflow([
                'message' => 'BRUSHCRAZY_API_URL / BRUSHCRAZY_API_SECRET are not configured for this app.',
                'entity' => null,
            ]);
        }

        if (filter_var($app->get(ConfigurationEnum::BRUSHCRAZY_API_DRY_RUN->value), FILTER_VALIDATE_BOOLEAN)) {
            return $this->dryRun($api, $path, $body);
        }

        try {
            $response = $api->post($path, $body);
        } catch (SsrfException $e) {
            return $this->failWorkflow([
                'message' => 'BRUSHCRAZY_API_URL is not an allowed address: ' . $e->getMessage(),
                'entity' => null,
            ]);
        } catch (RequestException $e) {
            if (! $e->response->clientError()) {
                throw $e;
            }

            return $this->failWorkflow([
                'message' => "BrushCrazy rejected {$path} with HTTP {$e->response->status()}.",
                'status' => $e->response->status(),
                'response' => $e->response->json() ?? $e->response->body(),
                'request' => $body,
                'entity' => null,
            ]);
        }

        return $onSuccess($response);
    }

    /**
     * `$onSuccess` is never called: nothing was booked, so nothing is stamped on the Kanvas side.
     * The body that would have gone is kept in the result for whoever checks the run.
     */
    private function dryRun(BrushCrazyApi $api, string $path, array $body): array
    {
        try {
            $status = $api->probe($path);
        } catch (SsrfException $e) {
            return $this->failWorkflow([
                'message' => 'BRUSHCRAZY_API_URL is not an allowed address: ' . $e->getMessage(),
                'entity' => null,
            ]);
        }

        if ($status !== 422) {
            return $this->failWorkflow([
                'message' => "Dry run: BrushCrazy answered {$path} with HTTP {$status}; 422 was expected (signature accepted, empty body refused).",
                'status' => $status,
                'entity' => null,
            ]);
        }

        return [
            'message' => "Dry run: BrushCrazy accepted the signature on {$path}; the body was not sent.",
            'dry_run' => true,
            'request' => $body,
            'entity' => null,
        ];
    }

    protected function skip(string $message): array
    {
        return [
            'message' => $message,
            'entity' => null,
        ];
    }
}
