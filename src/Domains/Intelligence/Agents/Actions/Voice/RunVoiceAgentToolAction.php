<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
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
        // Cross-app: run the tool in the AGENT's app + Bouncer scope, not the
        // runtime's app-key app. A write tool (create a lead, a channel, assign a
        // role) resolves People/Roles under the current scope, so without this a
        // cross-app agent hits ModelNotFoundException [People]/[Role] deep inside
        // the tool. Same binding as CaptureVoiceCallerAction; restored in finally.
        $previousApp = app(Apps::class);
        app()->instance(Apps::class, $this->agent->app);
        $previousScope = Bouncer::scope()->get();
        Bouncer::scope()->to(RolesEnums::getScope($this->agent->app));

        try {
            $handler = $this->resolveHandler();

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
                return $this->errorResult($e);
            }

            // Tools setResult(json_encode($array)); decode so the runtime gets structure.
            $result = $handler->getResult();
            $decoded = json_decode($result, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $result;
        } finally {
            Bouncer::scope()->to($previousScope);
            app()->instance(Apps::class, $previousApp);
        }
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
     * Resolve the runnable handler for $this->toolName among the agent's active
     * NEURON tools.
     *
     * The name to match is the HANDLER's own name ($handler->getName(), a valid
     * function-call identifier like `inventory_search`) — that is what
     * VoiceAgentSpecService advertises to the runtime and therefore what the LLM
     * calls back with. The catalog `Tool.name` column is a human display label
     * (e.g. `Inventory Search`) and does NOT match; keying off it made every tool
     * whose display name differs from its handler name uncallable mid-call. The
     * catalog name is kept only as a fallback for tools whose two names coincide.
     *
     * Fast path: a handler's name is almost always a literal `$name` default, read
     * by reflection without building the tool, so only the match is instantiated
     * (an agent can carry dozens of tools, each with its own constructor
     * dependencies). Slow path, only when that finds nothing: build the remaining
     * handlers and ask each for getName() — this keeps a tool whose name
     * is set at construction time (no literal default) callable, so run-time
     * selection still matches what VoiceAgentSpecService advertised.
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

            $handler = self::buildHandler($handlerClass);
            if ($handler !== null && $handler->getName() === $this->toolName) {
                return $handler;
            }
        }

        $fallback = null;

        foreach ($remaining as [$catalogTool, $handlerClass]) {
            $handler = self::buildHandler($handlerClass);
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

    /**
     * A handler that can't be built is skipped, not fatal. VoiceAgentSpecService
     * builds through this too, so run-time selection matches advertise-time.
     */
    public static function buildHandler(string $handlerClass): ?NeuronTool
    {
        try {
            $handler = app($handlerClass);
        } catch (Throwable) {
            return null;
        }

        return $handler instanceof NeuronTool ? $handler : null;
    }
}
