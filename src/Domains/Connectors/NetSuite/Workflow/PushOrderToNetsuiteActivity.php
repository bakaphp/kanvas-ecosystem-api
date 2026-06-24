<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Workflow;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\NetSuite\Actions\PushOrderToNetSuiteQuoteAction;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Actions\SendUserNotificationAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use RuntimeException;
use Throwable;

#[WorkflowAction]
class PushOrderToNetsuiteActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::NETSUITE,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                $netsuiteCustomerId = $order->user->getCurrentCompany()->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value) ?? null;

                $result = new PushOrderToNetSuiteQuoteAction($app, $order->company)
                        ->execute(
                            order: $order,
                            netsuiteCustomerId: $netsuiteCustomerId !== null ? (string) $netsuiteCustomerId : null,
                            createCustomerIfNotExists: false
                        );

                $responseData = [
                    'order' => $order->getId(),
                    'status' => $result['success'],
                    'message' => $result['message'],
                    'result' => $result['data'],
                ];

                if ($result['success'] === false) {
                    $errorMessage = $result['data']['error'] ?? $result['message'] ?? '';

                    if ($this->isNetSuiteRateLimitError($errorMessage)) {
                        throw new RuntimeException('NetSuite rate limit hit, retrying: ' . $errorMessage);
                    }

                    return $this->failWorkflow($responseData);
                }

                return $responseData;
            },
            company: $order->company,
            throwException: true,
        );
    }

    #[Override]
    public function failed(Throwable $throwable): void
    {
        [$order, $app] = $this->arguments + [null, null];

        if ($order instanceof Order) {
            report(new RuntimeException(sprintf(
                'NetSuite order sync exhausted retries for order %d: %s',
                $order->getId(),
                $throwable->getMessage()
            )));
        }

        if ($order instanceof Order && $app instanceof AppInterface) {
            new SendUserNotificationAction(
                $app,
                $order->company,
                $order->user
            )->execute('netsuite-order-sync-failed', [
                'order' => $order,
                'error' => $throwable->getMessage(),
            ]);
        }

        parent::failed($throwable);
    }

    protected function isNetSuiteRateLimitError(string $message): bool
    {
        $rateLimitPatterns = [
            'concurrent request limit exceeded',
            'request blocked',
            'rate limit',
            'too many requests',
        ];

        $lowerMessage = strtolower($message);

        foreach ($rateLimitPatterns as $pattern) {
            if (str_contains($lowerMessage, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
