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
                // Never leak a stack trace to the model; hand back calm, speakable copy.
                return ['status' => 'error', 'message' => $e->getMessage()];
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
     */
    private function resolveHandler(): NeuronTool
    {
        $tools = new CapabilityProvider()
            ->getActiveTools($this->agent, CapabilityFrameworkEnum::NEURON->value);

        $fallback = null;

        foreach ($tools as $catalogTool) {
            $handlerClass = $catalogTool->handler;
            if (empty($handlerClass) || ! class_exists($handlerClass)) {
                continue;
            }

            try {
                $handler = app($handlerClass);
            } catch (Throwable) {
                // A handler that can't be built is skipped, not fatal — mirrors
                // VoiceAgentSpecService, so run-time selection matches advertise-time.
                continue;
            }

            if (! $handler instanceof NeuronTool) {
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
}
