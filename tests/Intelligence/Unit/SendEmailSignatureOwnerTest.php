<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendEmailTool;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\Users\Models\Users;
use Mockery;
use Tests\TestCaseUnit;

final class SendEmailSignatureOwnerTest extends TestCaseUnit
{
    public function testOnlyAnExplicitHumanOnAnInternalAgentCanSupplyTheSignature(): void
    {
        $bot = new Users();
        $human = new Users();
        $tool = new class () extends SendEmailTool {
            public function owner(): ?Users
            {
                return $this->signatureOwner();
            }
        };
        $internal = Mockery::mock(Agent::class);
        $internal->shouldReceive('conversesWithUser')->andReturn(true);
        $external = Mockery::mock(Agent::class);
        $external->shouldReceive('conversesWithUser')->andReturn(false);

        $tool->withContext(
            new Apps(),
            new Companies(),
            $bot,
            $internal,
        );
        $this->assertNull($tool->owner());
        $tool->forConversationHuman($human);
        $this->assertSame($human, $tool->owner());
        $tool->withContext(
            new Apps(),
            new Companies(),
            $bot,
            $external,
        );
        $this->assertNull($tool->owner());
        $tool->withContext(new Apps(), new Companies(), $bot);
        $this->assertNull($tool->owner());
    }

    public function testRegistryWiresRequestingHumanRatherThanBotContext(): void
    {
        $human = new Users();
        $host = new class ($human) {
            use MergesRegisteredTools;

            public function __construct(private Users $human)
            {
            }

            public function requestingHuman(): Users
            {
                return $this->human;
            }

            public function wire(object $tool): object
            {
                return $this->fillKanvasContext($tool);
            }
        };
        $agent = Mockery::mock(Agent::class);
        $agent->shouldReceive('conversesWithUser')->andReturn(true);
        $tool = new class () extends SendEmailTool {
            public function owner(): ?Users
            {
                return $this->signatureOwner();
            }
        };
        $tool->withContext(
            new Apps(),
            new Companies(),
            new Users(),
            $agent,
        );
        $host->wire($tool);
        $this->assertSame($human, $tool->owner());
    }
}
