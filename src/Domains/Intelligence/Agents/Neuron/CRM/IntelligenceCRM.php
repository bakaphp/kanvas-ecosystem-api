<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\CRM;

use Illuminate\Support\Facades\Blade;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasMessageHistory;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ArtifactsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CommunicationChannelTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyInformationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyIsHolidayTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyWorkHoursTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompletionStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ContactCheckerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GoogleCalendarTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\HandOffTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadIntentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadRefTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SimilarVehiclesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\VehicleInterestTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\VehicleTradeInTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ListAvailableProductsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\VariantDetailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\VariantSearchTool;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

class IntelligenceCRM extends BaseKanvasAgent
{
    // protected function provider(): AIProviderInterface
    // {
    //     return new Ollama(
    //         url: 'http://198.19.249.3:11434/api',
    //         model: 'qwen3.6:35b',
    //         parameters: [], // Add custom params (temperature, logprobs, etc)
    //     );
    // }

    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        if ($this->entity === null || $this->user === null) {
            return new InMemoryChatHistory();
        }

        return new KanvasMessageHistory(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            entity: $this->entity,
            threadId: $this->threadId,
        );
    }

    #[Override]
    public function instructions(): string
    {
        $role = $this->agent->role;

        $background = Blade::render($role['background'], ['lead' => $this->entity]);
        $steps = Blade::render($role['steps'], ['lead' => $this->entity]);
        $output = Blade::render($role['output'], ['lead' => $this->entity]);
        $background = explode('\n', $background);

        return new SystemPrompt(
            background: [
                ...$background,
                "lead_id: {$this->entity->getId()}",
                "companies_id: {$this->entity->companies_id}",
            ],
            steps: explode('\n', $steps),
            output: explode('\n', $output),
        )->__toString();
    }

    #[Override]
    protected function tools(): array
    {
        $tools = [
            new ArtifactsTool(),
            new CommunicationChannelTool(),
            new CompanyInformationTool(),
            new CompanyIsHolidayTool(),
            new CompanyWorkHoursTool(),
            new CompletionStatusTool(),
            new GoogleCalendarTool(),
            new HandOffTool(),
            new LeadIntentTool(),
            new LeadRefTool(),
            new SimilarVehiclesTool(),
            new VehicleInterestTool(),
            new VehicleTradeInTool(),
            new InventorySearchTool(),
            new ListAvailableProductsTool(),
            new VariantSearchTool(),
            new VariantDetailTool(),
        ];

        if ($this->entity instanceof Message) {
            $tools[] = new ContactCheckerTool($this->entity);
        }

        return $tools;
    }
}
