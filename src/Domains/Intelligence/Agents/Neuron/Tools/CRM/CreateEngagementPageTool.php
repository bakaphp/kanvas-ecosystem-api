<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Support\Str;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Sentry\State\Scope;
use Throwable;

use function Sentry\captureException;
use function Sentry\withScope;

#[AgentTool(name: 'Create Engagement Page', category: 'crm')]
class CreateEngagementPageTool extends Tool
{
    use HasKanvasContext;

    public function __construct(private readonly Agent $agent)
    {
        parent::__construct(
            name: 'create_engagement_page',
            description: 'Create one tracked Action Engine page and its internal message for a lead, then return its action URL. '
                . 'Use an action slug such as view-vehicle, get-docs, credit-app or add-trade. '
                . 'For view-vehicle, pass the selected vehicle UUIDs in product_id. '
                . 'This tool does not contact the customer; pass the returned action_link to send_sms or send_email only when instructed.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead that will own the engagement page.',
                required: true,
            ),
            new ToolProperty(
                name: 'action',
                type: PropertyType::STRING,
                description: 'Action slug for the page, for example view-vehicle, get-docs, credit-app or add-trade.',
                required: true,
            ),
            new ToolProperty(
                name: 'data',
                type: PropertyType::OBJECT,
                description: 'Additional action-specific page payload. Do not use this field for vehicle or channel IDs.',
                required: false,
            ),
            new ToolProperty(
                name: 'product_id',
                type: PropertyType::ARRAY,
                description: 'Selected vehicle variant UUIDs. Use this for view-vehicle so the generated message follows the Share Product format.',
                required: false,
                items: new ToolProperty(
                    name: 'vehicle_uuid',
                    type: PropertyType::STRING,
                    description: 'A selected vehicle variant UUID.',
                ),
            ),
            new ToolProperty(
                name: 'channel_id',
                type: PropertyType::STRING,
                description: 'Optional engagement channel UUID to preserve in the generated message payload.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        int $lead_id,
        string $action,
        ?array $data = null,
        array $product_id = [],
        ?string $channel_id = null,
    ): array {
        $data ??= [];

        $action = trim($action);
        if ($action === '') {
            return [
                'status' => 'error',
                'message' => 'The action slug is required.',
            ];
        }

        try {
            $lead = Lead::getByIdFromCompanyApp($lead_id, $this->company, $this->app);
        } catch (Throwable) {
            return [
                'status' => 'error',
                'message' => "Lead {$lead_id} does not exist in the current app and company.",
            ];
        }

        try {
            $agentUser = $this->agent->user;
            if ($agentUser === null) {
                return [
                    'status' => 'error',
                    'message' => 'The agent must have an acting user configured to create an engagement page.',
                ];
            }

            $data = $this->normalizeActionData($action, $data, $product_id, $channel_id);

            $engagement = $this->createEngagement(new EngagementData(
                app: $this->app,
                company: $this->company,
                user: $agentUser,
                lead: $lead,
                action: $action,
                requestId: (string) Str::uuid(),
                source: 'agent',
                status: ActionStatusEnum::SENT,
                people: $lead->people,
                via: 'agent',
                data: $data,
            ));
        } catch (Throwable $e) {
            $this->reportException($e, $lead_id, $action);

            return [
                'status' => 'error',
                'message' => 'The engagement page could not be created. Verify the action and company configuration.',
            ];
        }

        $message = $engagement->message;
        $messageData = is_array($message?->message) ? $message->message : [];
        $actionLink = $messageData['action_link'] ?? null;

        if (! is_string($actionLink) || $actionLink === '') {
            return [
                'status' => 'error',
                'message' => 'The engagement was created but no action URL was generated.',
                'engagement_id' => $engagement->getId(),
                'engagement_uuid' => (string) $engagement->uuid,
            ];
        }

        return [
            'status' => 'success',
            'lead_id' => $lead->getId(),
            'action' => $action,
            'engagement_id' => $engagement->getId(),
            'engagement_uuid' => (string) $engagement->uuid,
            'message_id' => $message?->getId(),
            'action_link' => $actionLink,
            'delivery' => 'not_sent',
        ];
    }

    /**
     * Preserve the historical Action Engine message shape used by Share Product.
     *
     * @param array<string, mixed> $data
     * @param array<int, string> $productIds
     *
     * @return array<string, mixed>
     */
    protected function normalizeActionData(string $action, array $data, array $productIds, ?string $channelId): array
    {
        if ($action !== 'view-vehicle') {
            return $data;
        }

        if ($productIds === [] && isset($data['products']) && is_array($data['products'])) {
            $productIds = array_values(array_filter(array_map(
                static fn (mixed $product): ?string => is_array($product) && isset($product['id']) && is_string($product['id'])
                    ? $product['id']
                    : null,
                $data['products'],
            )));
            unset($data['products']);
        }

        if ($productIds !== []) {
            $data['product_id'] = array_values($productIds);
        }

        if (is_string($channelId) && trim($channelId) !== '') {
            $data['channel_id'] = trim($channelId);
        }

        return $data;
    }

    protected function createEngagement(EngagementData $data): Engagement
    {
        return new CreateEngagementAction($data)->execute();
    }

    protected function reportException(Throwable $exception, int $leadId, string $action): void
    {
        withScope(function (Scope $scope) use ($exception, $leadId, $action): void {
            $scope->setTag('operation', 'create_engagement_page');
            $scope->setTag('action_slug', $action);
            $scope->setContext('create_engagement_page', [
                'lead_id' => $leadId,
                'action' => $action,
                'app_id' => $this->app->getId(),
                'company_id' => $this->company->getId(),
                'user_id' => $this->agent->user_id,
            ]);

            captureException($exception);
        });
    }
}
