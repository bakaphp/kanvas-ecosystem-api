<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Notes\Services;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\HumanResources\Meetings\Models\Meeting;
use Kanvas\HumanResources\Meetings\Notes\Agents\MeetingNotesAgent;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Agents\Services\LlmHttpRetryService;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;

class MeetingNotesAgentService
{
    /**
     * The company's own agent of the Meeting Notes type, when it hired one to choose the model.
     */
    public function agentFor(Meeting $meeting): ?Agent
    {
        return Agent::query()
            ->fromApp($meeting->apps_id)
            ->where('companies_id', $meeting->companies_id)
            ->whereHas('type', fn ($query) => $query->where('handler', MeetingNotesAgent::class))
            ->notDeleted()
            ->orderBy('id')
            ->first();
    }

    /**
     * Which model writes, first match wins: the company agent's own provider, then the app's Gemini
     * model (`GEMINI_MODEL`, else MeetingNotesAgent::DEFAULT_MODEL) on the app's Gemini key.
     */
    public function handlerFor(Meeting $meeting): MeetingNotesAgent
    {
        $agent = $this->agentFor($meeting);
        $handler = new MeetingNotesAgent();

        if ($agent !== null) {
            return $handler->runWith(
                AgentProviderService::resolve($agent),
                AgentProviderService::resolveProviderEnum($agent)->value,
            );
        }

        $app = $meeting->app;
        $key = Str::trimToNull($app->get(ConfigurationEnum::GEMINI_KEY->value));

        if ($key === null) {
            throw new ValidationException(
                'No Gemini API key to write meeting notes with. Set the app '
                . ConfigurationEnum::GEMINI_KEY->value . ' setting, or hire a Meeting Notes agent.'
            );
        }

        return $handler->runWith(
            new KanvasGemini(
                key: $key,
                model: Str::trimToNull($app->get(ConfigurationEnum::GEMINI_MODEL->value)) ?? MeetingNotesAgent::DEFAULT_MODEL,
                httpClient: new GuzzleHttpClient(
                    timeout: 220,
                    connectTimeout: 30,
                    handler: LlmHttpRetryService::handlerStack(),
                ),
            ),
            AgentLlmProviderEnum::GEMINI->value,
        );
    }
}
