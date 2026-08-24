<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
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
    use DecodesJsonObjectParam;
    use HasKanvasContext;

    public function __construct(private readonly Agent $agent)
    {
        parent::__construct(
            name: 'create_engagement_page',
            description: 'Create one tracked Action Engine page for a lead and return its action URL. '
                . 'Use an action slug such as view-vehicle, get-docs, credit-app or add-trade. '
                . 'This tool does not contact the customer; pass the returned action_link to send_sms or send_email.',
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
                type: PropertyType::STRING,
                description: 'Action-specific page payload, as a JSON object passed in a string. '
                    . 'Credit-app and actions without fields may omit data. '
                    . 'For view-vehicle, provide product_id as an array of real variant numeric IDs or UUIDs. '
                    . 'You may provide channel_id as an inventory channel numeric ID or UUID; when omitted, the '
                    . 'tool resolves the default published inventory channel shared by the variants. Never invent IDs.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id, string $action, array|string|null $data = null): array
    {
        $data = $this->decodeJsonObjectParam($data);

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

            $data = $this->normalizeActionData($action, $data);

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
        } catch (InvalidArgumentException $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
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

    protected function createEngagement(EngagementData $data): Engagement
    {
        return new CreateEngagementAction($data)->execute();
    }

    /**
     * Normalize action-specific input to the same payload produced by the frontend.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function normalizeActionData(string $action, array $data): array
    {
        if ($action !== 'view-vehicle') {
            return $data;
        }

        $productIds = $data['product_id'] ?? null;
        if (! is_array($productIds) || $productIds === []) {
            throw new InvalidArgumentException(
                'View vehicle requires data.product_id with at least one real variant numeric ID or UUID.',
            );
        }

        $variants = collect($productIds)->map(function (mixed $productId): Variants {
            if (! is_int($productId) && ! is_string($productId)) {
                throw new InvalidArgumentException('Every view-vehicle product_id must be a variant numeric ID or UUID.');
            }

            $identifier = trim((string) $productId);
            $variant = Variants::query()
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->notDeleted()
                ->where(
                    fn ($query) => ctype_digit($identifier)
                        ? $query->whereKey((int) $identifier)
                        : $query->where('uuid', $identifier)
                )
                ->first();

            if (! $variant instanceof Variants) {
                throw new InvalidArgumentException("Variant {$identifier} was not found in the current app and company.");
            }

            return $variant;
        })->unique(fn (Variants $variant): int => $variant->getId())->values();

        $channel = $this->resolveInventoryChannel($data['channel_id'] ?? null, $variants->first());

        foreach ($variants as $variant) {
            $hasPublishedChannel = $variant->variantChannels()
                ->where('channels_id', $channel->getId())
                ->where('is_published', true)
                ->exists();

            if (! $hasPublishedChannel) {
                throw new InvalidArgumentException(
                    "Variant {$variant->getId()} is not available in inventory channel {$channel->uuid}.",
                );
            }
        }

        unset($data['products']);
        $data['product_id'] = $variants
            ->map(fn (Variants $variant): string => (string) $variant->uuid)
            ->all();
        $data['channel_id'] = (string) $channel->uuid;

        return $data;
    }

    protected function resolveInventoryChannel(mixed $channelId, Variants $variant): Channels
    {
        if ($channelId === null || trim((string) $channelId) === '') {
            try {
                return $variant->getPriceInfoFromDefaultChannel();
            } catch (Throwable) {
                throw new InvalidArgumentException(
                    'No default published inventory channel is available for the selected view-vehicle variant.',
                );
            }
        }

        if (! is_int($channelId) && ! is_string($channelId)) {
            throw new InvalidArgumentException('The view-vehicle channel_id must be an inventory channel numeric ID or UUID.');
        }

        $identifier = trim((string) $channelId);
        $channel = Channels::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('is_published', true)
            ->where(
                fn ($query) => ctype_digit($identifier)
                    ? $query->whereKey((int) $identifier)
                    : $query->where('uuid', $identifier)
            )
            ->first();

        if (! $channel instanceof Channels) {
            throw new InvalidArgumentException(
                "Inventory channel {$identifier} was not found or is not published in the current app and company.",
            );
        }

        return $channel;
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
