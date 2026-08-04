<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Contracts;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use NeuronAI\Providers\AIProviderInterface;

interface KanvasNeuronAgent extends ProvidesToolDependencies
{
    public function setConfiguration(Agent $agent, ?Model $entity = null, ?string $externalReferenceId = null, ?Users $user = null): void;

    public function setThreadId(string $threadId): void;

    public function setSession(?Session $session): void;

    public function setCurrentLead(?Lead $lead): void;

    /** @param list<string> $media */
    public function setTurnMedia(array $media): void;

    public function persistsTurnsToConversationStore(): bool;

    public function captionProvider(): AIProviderInterface;

    public function resolvedModelName(): string;
}
