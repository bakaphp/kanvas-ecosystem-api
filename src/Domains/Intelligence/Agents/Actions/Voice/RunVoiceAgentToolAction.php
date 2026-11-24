<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceAgentSpecService;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;
use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Tools\Tool as NeuronTool;
use ReflectionClass;
use Throwable;

/**
 * Voice-agent data plane: execute ONE of an agent's tools by name and return its
 * result. This is what the external voice runtime hits when the LLM makes a
 * function call mid-call.
 *
 * The tool gets the agent's app, company and AI user as its context. Entity ids arrive as
 * tool arguments, and NeuronAI's Tool::execute() maps and dispatches them exactly as on the
 * in-process agent path.
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
        $handler = $this->resolveHandler();

        // The request comes in on the runtime's app key, so the acting user is the company's AI
        // user, never auth().
        $company = $this->agent->company;
        if ($company !== null && method_exists($handler, 'withContext')) {
            $handler->withContext($this->agent->app, $company, $company->getAiAgentUserOrFail());
        }

        try {
            $handler->setInputs($this->arguments);
            $handler->execute();
        } catch (Throwable $e) {
            return $this->errorResult($e);
        }

        // Tools setResult(json_encode($array)); decode so the runtime gets structure.
        $result = $handler->getResult();
        $decoded = json_decode($result, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $result;
    }

    /**
     * What the model hears when a tool throws. The voice agent may say it out loud, so only
     * messages written for it pass through: our validation errors and bad-argument errors (the
     * model can fix the arguments and retry). Anything else is reported and replaced with short,
     * neutral copy, so SQL, search-engine or model-not-found text never reaches a caller.
     *
     * @return array{status: string, reason: string, message: string}
     */
    private function errorResult(Throwable $e): array
    {
        if ($e instanceof ValidationException
            || $e instanceof InvalidToolInput
            || $e instanceof MissingCallbackParameter
        ) {
            return ['status' => 'error', 'reason' => 'invalid_request', 'message' => $e->getMessage()];
        }

        report($e);

        return [
            'status' => 'error',
            'reason' => 'tool_failed',
            'message' => "That couldn't be completed right now. Offer to have someone follow up.",
        ];
    }

    /**
     * The handler for $this->toolName among the agent's active NEURON tools.
     *
     * The name matched is the HANDLER's ($handler->getName(), e.g. `inventory_search`): that is
     * what VoiceAgentSpecService advertises and so what the LLM calls back with. The catalog
     * `Tool.name` is a display label (`Inventory Search`) and only a fallback for tools whose two
     * names coincide.
     *
     * A handler's name is almost always a literal `$name` default, read by reflection, so only
     * the match is built (an agent can carry dozens of tools, each with constructor dependencies).
     * When that finds nothing the rest are built and asked, which keeps a tool named at
     * construction time callable.
     */
    private function resolveHandler(): NeuronTool
    {
        $tools = new CapabilityProvider()
            ->getActiveTools($this->agent, CapabilityFrameworkEnum::NEURON->value);

        $remaining = [];

        foreach ($tools as $catalogTool) {
            $handlerClass = $catalogTool->handlerClass();
            if ($handlerClass === null) {
                continue;
            }

            if (self::declaredToolName($handlerClass) !== $this->toolName) {
                $remaining[] = [$catalogTool, $handlerClass];

                continue;
            }

            $handler = VoiceAgentSpecService::buildHandler($handlerClass);
            if ($handler !== null && $handler->getName() === $this->toolName) {
                return $handler;
            }
        }

        $fallback = null;

        foreach ($remaining as [$catalogTool, $handlerClass]) {
            $handler = VoiceAgentSpecService::buildHandler($handlerClass);
            if ($handler === null) {
                continue;
            }

            if ($handler->getName() === $this->toolName) {
                return $handler;
            }

            if ($fallback === null && $catalogTool->name === $this->toolName) {
                $fallback = $handler;
            }
        }

        if ($fallback !== null) {
            return $fallback;
        }

        throw new ValidationException("Agent has no active tool named '{$this->toolName}'.");
    }

    /**
     * The literal `$name` default a NeuronAI tool class declares, read without
     * instantiating it; null when the class isn't a Neuron tool or sets its name at
     * construction time.
     */
    public static function declaredToolName(string $handlerClass): ?string
    {
        if (! is_subclass_of($handlerClass, NeuronTool::class)) {
            return null;
        }

        $name = new ReflectionClass($handlerClass)->getDefaultProperties()['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
