<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\CreateRoadsideProviderCaseAction;
use Kanvas\Connectors\Movipass\Actions\PushRoadsideProviderStateAction;
use Kanvas\Connectors\Movipass\Actions\RegisterRoadsideProviderContactAction;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Jobs\PollRoadsideProviderCaseJob;
use Kanvas\Connectors\Movipass\Support\RoadsideProviderCaseNumber;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Mirrors a roadside assistance case onto the external provider platform. Kept separate from
 * SyncMovipassRoadsideAssistanceActivity so an app opts in through its own workflow rule, rather
 * than every roadside case reaching out to a provider the company may not use.
 */
#[WorkflowAction]
class SyncMovipassRoadsideProviderActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use InteractsWithAssistanceCase;

    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                if ($order->orderType?->name !== OrderTypeEnum::ROADSIDE_ASSISTANCE->value) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Order is not a roadside assistance type',
                    ];
                }

                return match ($additionalParams['currentEventTypeName'] ?? null) {
                    WorkflowEnum::CREATED->value => $this->registerCase($order, $app),
                    WorkflowEnum::STATUS_TRANSITION->value => $this->pushState(
                        $order,
                        $additionalParams['to_status'] ?? null,
                    ),
                    WorkflowEnum::UPDATED->value => $this->pushAssignedContact($order),
                    default => [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'No provider sync required for this event',
                    ],
                };
            },
            company: $order->company,
        );
    }

    private function registerCase($order, AppInterface $app): array
    {
        $payload = $this->assistanceCaseFrom($order)['provider_payload'] ?? null;

        // Until the provider's create-call field list is confirmed the payload is supplied by the
        // caller. A case without one is not an error: the local case is valid and can be registered
        // by hand or once the mapper lands.
        if (! is_array($payload) || $payload === []) {
            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'Skipped: the case carries no assistance_case.provider_payload to register',
            ];
        }

        try {
            $number = new CreateRoadsideProviderCaseAction($order, $payload)->execute();
        } catch (ValidationException $exception) {
            return [
                'order' => $order->getId(),
                'status' => 'error',
                'message' => $exception->getMessage(),
            ];
        }

        $this->startPolling($order, $app);

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Case registered with the roadside assistance provider',
            'provider_case_number' => $number,
        ];
    }

    private function pushState($order, ?string $toStatus): array
    {
        if (! is_string($toStatus) || $toStatus === '') {
            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'No status to push',
            ];
        }

        if (RoadsideProviderCaseNumber::for($order) === null) {
            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'Skipped: the case is not registered with the provider',
            ];
        }

        try {
            $pushed = new PushRoadsideProviderStateAction($order, $toStatus)->execute();
        } catch (ValidationException $exception) {
            return [
                'order' => $order->getId(),
                'status' => 'error',
                'message' => $exception->getMessage(),
            ];
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => $pushed
                ? 'State pushed to the provider: ' . $toStatus
                : 'State already matched on the provider: ' . $toStatus,
        ];
    }

    /**
     * The assignment step: once a unit is on the case the provider is told who is coming.
     */
    private function pushAssignedContact($order): array
    {
        $contact = $this->assistanceCaseFrom($order)['provider_contact'] ?? null;

        if (! is_array($contact) || $contact === [] || RoadsideProviderCaseNumber::for($order) === null) {
            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'Skipped: no provider contact to register',
            ];
        }

        try {
            new RegisterRoadsideProviderContactAction($order, $contact)->execute();
        } catch (ValidationException $exception) {
            return [
                'order' => $order->getId(),
                'status' => 'error',
                'message' => $exception->getMessage(),
            ];
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Provider contact registered',
        ];
    }

    /**
     * Terminal statuses come from the order type's own `is_final` rows rather than a hardcoded
     * list, so the poll loop stops on whatever endings the flow actually defines.
     */
    private function startPolling($order, AppInterface $app): void
    {
        $terminalStatuses = $order->orderType
            ?->statuses()
            ->where('is_final', 1)
            ->pluck('slug')
            ->map(static fn (mixed $slug): string => (string) $slug)
            ->all() ?? [];

        // The job takes a concrete Apps so SerializesModels can round-trip it through the queue.
        PollRoadsideProviderCaseJob::dispatch(
            $app instanceof Apps ? $app : Apps::getById((int) $app->getId()),
            $order,
            $terminalStatuses,
        );
    }
}
