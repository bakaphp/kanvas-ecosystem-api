<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Intelligence\Agents\Neuron\Coding\ProgrammingAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Templates\CreateTemplateTool;
use Kanvas\Intelligence\Agents\Neuron\Traits\HasKanvasAgentBehavior;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A deliverable goes in the record, not in the message.
 *
 * The HTML Template Designer stored template `crm_metrics_card_22975` at 21:02:46 and then pasted the
 * same 2610 characters into plan 22975's thread six seconds later. It had done the work correctly —
 * nothing told it how to HAND OVER the result, so it did both. A PM then hand-wrote the rule into that
 * one agent's `instructions` column, which fixes exactly one agent on one day.
 *
 * So the rule lives in the platform context, next to "Kanvas is the orchestrator": one place, every
 * agent, no per-agent authoring.
 */
final class ArtifactDeliveryDoctrineTest extends TestCase
{
    public function testEveryAgentIsToldADeliverableIsNotTheBodyOfAMessage(): void
    {
        $context = implode(' ', HasKanvasAgentBehavior::platformContext());

        $this->assertStringContainsString('A DELIVERABLE IS NEVER THE BODY OF A MESSAGE', $context);
        $this->assertStringContainsString(
            'create_template',
            $context,
            'The rule has to name where the artifact goes, or it is only a prohibition.'
        );
        $this->assertStringContainsString('generate_template_pdf', $context);
        $this->assertStringContainsString(
            'Never paste markup, code or a document body',
            $context,
            'HTML was the case that surfaced it; the rule covers any document body.'
        );
        $this->assertStringContainsString(
            'say which one you are missing rather than pasting it anyway',
            $context,
            'Without a tool the agent must ask, not fall back to pasting.'
        );
        $this->assertStringContainsString(
            'get_file_link',
            $context,
            'Naming the artifact is only half of handing it over — the reader needs a link, not an id.'
        );
    }

    /**
     * An agent that writes its own `instructions()` never reaches the SystemPrompt the platform context
     * is assembled into, so it was exempt from every rule there. Both of ours were.
     *
     * @return array<string, array{0: class-string}>
     */
    public static function agentsThatAuthorTheirOwnInstructions(): array
    {
        return [
            'project manager' => [ProjectManagerAgent::class],
            'programming' => [ProgrammingAgent::class],
        ];
    }

    /**
     * @param class-string $agentClass
     */
    #[DataProvider('agentsThatAuthorTheirOwnInstructions')]
    public function testAnAgentWithItsOwnPromptStillCarriesThePlatformContext(string $agentClass): void
    {
        $render = new ReflectionMethod($agentClass, 'platformContextBlock');

        $this->assertStringContainsString(
            'A DELIVERABLE IS NEVER THE BODY OF A MESSAGE',
            (string) $render->invoke(new $agentClass()),
            $agentClass . ' writes its own instructions and would otherwise be exempt from the rule.'
        );
    }

    /** A generic hired agent — what `hire_agent` produces — reaches it through the SystemPrompt. */
    public function testAHiredAgentReachesTheRuleThroughTheSharedPrompt(): void
    {
        $render = new ReflectionMethod(KanvasGenericNeuronAgent::class, 'platformContextBlock');

        $this->assertStringContainsString(
            'A DELIVERABLE IS NEVER THE BODY OF A MESSAGE',
            (string) $render->invoke(new KanvasGenericNeuronAgent()),
        );
    }

    /**
     * The prompt states the rule; the tool has to restate it at the moment of choosing, because that is
     * where the agent decides between the `html` argument and its reply.
     */
    public function testTheTemplateToolSaysTheMarkupBelongsInTheToolCall(): void
    {
        $description = new CreateTemplateTool()->getDescription();

        $this->assertStringContainsString('never repeat it in your reply', $description);
        $this->assertStringContainsString(
            'update_template',
            $description,
            'Hitting "already exists" with no next step is what produced "it looks like that already exists".'
        );
    }
}
