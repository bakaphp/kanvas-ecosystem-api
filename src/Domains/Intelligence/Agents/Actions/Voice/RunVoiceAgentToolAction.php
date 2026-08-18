<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;
use NeuronAI\Tools\Tool as NeuronTool;
use Throwable;

/**
 * Voice-agent data plane: execute ONE of an agent's tools by name and return its
 * result. This is what the external voice runtime hits when the LLM makes a
 * function call mid-call.
 *
 * The tool runs with the AGENT's own context — its app, its company, and its
 * dedicated AI user (a non-request path, so we can't lean on auth()). Entity ids
 * (lead, product, …) arrive as tool arguments, exactly like a chat-turn tool
 * call. Reuses NeuronAI's Tool::execute() so argument mapping + dispatch match
 * the in-process agent path.
 */
class RunVoiceAgentToolAction
{
    /**
     * @param array<string, mixed> $arguments the LLM-supplied tool arguments
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly string $toolName,
        private readonly array $arguments,
    ) {
    }

    /**
     * @return mixed the tool's result (decoded from its JSON output), or a
     *               structured error array the LLM can speak
     */
    public function execute(): mixed
    {
        $catalogTool = new CapabilityProvider()
            ->getActiveTools($this->agent, CapabilityFrameworkEnum::NEURON->value)
            ->first(fn ($tool): bool => $tool->name === $this->toolName);

        if ($catalogTool === null) {
            throw new ValidationException("Agent has no active tool named '{$this->toolName}'.");
        }

        $handlerClass = $catalogTool->handler;
        if (empty($handlerClass) || ! class_exists($handlerClass)) {
            throw new ValidationException("Tool '{$this->toolName}' has no runnable handler.");
        }

        $handler = app($handlerClass);
        if (! $handler instanceof NeuronTool) {
            throw new ValidationException("Tool '{$this->toolName}' is not executable.");
        }

        // Wire the agent's tenant context onto tools that accept it (withContext
        // from HasKanvasContext). app/company come from the agent; the acting
        // user is the company's dedicated AI user for this non-request path.
        $company = $this->agent->companies_id > 0 ? Companies::find($this->agent->companies_id) : null;
        if ($company !== null && method_exists($handler, 'withContext')) {
            $handler->withContext($this->agent->app, $company, $company->getAiAgentUserOrFail());
        }

        try {
            $handler->setInputs($this->arguments);
            $handler->execute();
        } catch (Throwable $e) {
            // Never leak a stack trace to the model; hand back calm, speakable copy.
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Tools setResult(json_encode($array)); decode so the runtime gets structure.
        $result = $handler->getResult();
        $decoded = json_decode($result, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $result;
    }
}
