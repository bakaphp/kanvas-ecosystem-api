<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\Company\CompanyBrainAgent;
use Kanvas\Users\Models\Users;
use NeuronAI\Providers\AIProviderInterface;
use Override;
use Tests\Stubs\Intelligence\Concerns\RunsOffline;

/**
 * The real Company Brain answering offline with whatever the test scripted, so a wake can run end to
 * end. Who the turn ran as is kept in a static sink: the wake builds the agent out of the test's reach.
 */
class ScriptedCompanyBrainAgentStub extends CompanyBrainAgent
{
    use RunsOffline;

    public static string $reply = 'Done.';
    public static ?Users $lastTurnUser = null;
    public static ?bool $lastUnattended = null;

    #[Override]
    protected function provider(): AIProviderInterface
    {
        self::$lastTurnUser = $this->user;
        self::$lastUnattended = $this->isUnattendedTurn();

        return new FakeNeuronProvider(self::$reply);
    }

    #[Override]
    public function instructions(): string
    {
        return 'Scripted Company Brain';
    }
}
