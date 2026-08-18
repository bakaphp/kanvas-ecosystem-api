<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Workflow;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesWorkflowCatalogForTool;
use Kanvas\NervousSystem\Capability\Enums\AgentAbilityEnum;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Give this company an inbound endpoint — a URL an outside system posts to, which then runs a
 * receiver job. The other half of automation: workflows react to records Kanvas already has, a
 * receiver is how a record gets in from outside in the first place.
 *
 * Admin only, and for a blunter reason than the workflow tools: this mints a **publicly reachable
 * URL** that accepts data into the tenant. The endpoint is unguessable (a uuid) rather than
 * authenticated, so creating one is closer to handing out a credential than to changing a setting.
 */
#[AgentTool(name: 'Create Company Receiver', category: 'workflow')]
class CreateCompanyReceiverTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use ResolvesWorkflowCatalogForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_company_receiver',
            description: 'Create an inbound endpoint for THIS company: a URL an outside system can POST to, '
                . 'which then runs the receiver you name — turning a landing-page form, a partner feed or an '
                . 'external system into records in Kanvas. Admin only. Call list_workflow_options with '
                . 'kind "receivers" first to see what this app can receive and use a name from there '
                . 'verbatim. Returns the URL, which the person you are talking to has to give to whoever '
                . 'sends the data.',
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
                name: 'receiver',
                type: PropertyType::STRING,
                description: 'Which receiver handles the incoming data, from list_workflow_options '
                    . '(kind: "receivers"). Never invent one.',
                required: true,
            ),
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Short name for this endpoint, e.g. "Website contact form". A company usually '
                    . 'has several of the same receiver type, so name it after the SOURCE, not the type.',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Optional note about what sends to it and why.',
                required: false,
            ),
            new ToolProperty(
                name: 'configuration',
                type: PropertyType::STRING,
                description: 'Optional JSON object of settings for this endpoint, e.g. '
                    . '{"region_id": 1}. What it accepts depends on the receiver — leave it out unless you '
                    . 'know what the receiver reads.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $receiver,
        string $name,
        ?string $description = null,
        ?string $configuration = null,
    ): array {
        if ($denied = $this->requireRequestingAdminOrError()) {
            return $denied;
        }

        if (! $this->hasTenantContext() || ! isset($this->user)) {
            return $this->error('This agent has no company context, so it cannot create a receiver.');
        }

        $name = trim($name);

        if ($name === '') {
            return $this->error('The endpoint needs a name. Ask the admin what sends to it.');
        }

        $action = $this->resolveReceiver($receiver);

        if ($action === null) {
            return $this->error(
                sprintf('"%s" is not a receiver this app can accept. Pick one from suggested_receivers.', trim($receiver)),
                ['suggested_receivers' => $this->searchReceivers($receiver) ?: $this->searchReceivers()],
            );
        }

        $config = [];

        if ($configuration !== null && trim($configuration) !== '') {
            $decoded = json_decode(trim($configuration), true);

            if (! is_array($decoded) || array_is_list($decoded)) {
                return $this->error('configuration must be a JSON object of name/value pairs, e.g. {"region_id": 1}.');
            }

            $config = $decoded;
        }

        // The DTO wants the WorkflowAction model; the catalog resolves the Rules\Action model. Same
        // row, same table, two mappings — fetch by id rather than casting between them.
        $workflowAction = WorkflowAction::query()->whereKey($action->getId())->first();

        if ($workflowAction === null) {
            return $this->error('That receiver could not be loaded. Tell the admin and do not retry.');
        }

        try {
            $created = new CreateReceiverWebhookAction(new ReceiverWebhookData(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                action: $workflowAction,
                name: $name,
                description: $description !== null && trim($description) !== '' ? trim($description) : null,
                configuration: $config,
            ))->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->error('The receiver could not be created. Tell the admin it failed and do not retry.');
        }

        return [
            'created' => true,
            'receiver_id' => $created->getId(),
            'name' => $created->name,
            'receiver' => $action->name,
            'url' => $this->urlFor($created),
            'scope' => 'company:' . $this->company->name,
            'message' => 'Give this URL to whoever sends the data; they POST to it. It is not password '
                . 'protected — anyone holding the URL can post to it, so treat it like a secret and do not '
                . 'publish it anywhere public.',
        ];
    }

    private function urlFor(ReceiverWebhook $receiver): string
    {
        return rtrim((string) config('app.url'), '/') . '/receiver/' . $receiver->uuid;
    }

    /**
     * @return list<string>
     */
    protected function requiredAbilities(): array
    {
        return [AgentAbilityEnum::MANAGE_COMPANY_WORKFLOWS->value];
    }
}
