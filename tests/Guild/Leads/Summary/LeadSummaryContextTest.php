<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Summary;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Guild\Leads\Summary\LeadSummaryBatch;
use Kanvas\Guild\Leads\Summary\LeadSummaryContext;
use Kanvas\Guild\Leads\Summary\LeadSummaryFold;
use Kanvas\Guild\Leads\Summary\LeadSummaryRepository;
use Kanvas\Guild\Leads\Summary\LeadSummaryState;
use Kanvas\Guild\Leads\Summary\Output\KeyFact;
use Kanvas\Guild\Leads\Summary\Output\LeadSummaryUpdate;
use Kanvas\Intelligence\Agents\Enums\AgentRunConfigurationEnum;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\JsonSchema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\MakesLeadConversations;

/**
 * The sections are the same for every business; what a business tracks beyond them is configured per
 * company or app, named after lead custom fields, and the lead's own value is shown to the model.
 *
 * Serial: the keys setting is written to Redis, which every paratest process shares.
 */
#[Group('serial')]
final class LeadSummaryContextTest extends TestCase
{
    use DatabaseTransactions;
    use MakesLeadConversations;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    public function testConfiguredKeysAndTheLeadsOwnValuesReachThePrompt(): void
    {
        $lead = $this->makeLead();
        $lead->company->set(AgentRunConfigurationEnum::LEAD_SUMMARY_KEYS->value, [
            ['key' => 'vehicle_of_interest', 'description' => 'year, make, model and trim they want'],
            'trade_in',
        ]);
        $lead->set('vehicle_of_interest', ['year' => 2022, 'make' => 'Toyota', 'model' => 'Tacoma']);

        try {
            $context = LeadSummaryContext::for($lead);
        } finally {
            $lead->company->del(AgentRunConfigurationEnum::LEAD_SUMMARY_KEYS->value);
        }

        $this->assertStringContainsString('- vehicle_of_interest: year, make, model and trim they want', $context);
        $this->assertStringContainsString('- trade_in', $context);
        $this->assertStringContainsString('On the lead record now', $context);
        $this->assertStringContainsString('"model":"Tacoma"', $context, 'The CRM value is shown as known data');
        $prompt = LeadSummaryFold::prompt(
            't',
            new LeadSummaryState(),
            new LeadSummaryBatch([1 => ['date' => 'd', 'line' => 'l']]),
            context: $context,
        );
        $this->assertStringContainsString('Facts this business tracks', $prompt);
    }

    public function testACommaSeparatedStringSettingWorksToo(): void
    {
        $lead = $this->makeLead();
        $lead->app->set(AgentRunConfigurationEnum::LEAD_SUMMARY_KEYS->value, 'policy_term, Renewal Date');

        try {
            $this->assertSame(['policy_term' => '', 'renewal_date' => ''], LeadSummaryContext::keys($lead));
        } finally {
            $lead->app->del(AgentRunConfigurationEnum::LEAD_SUMMARY_KEYS->value);
        }
    }

    public function testNoKeysMeansOnlyTheCompanyProfile(): void
    {
        $lead = $this->makeLead();

        $this->assertStringNotContainsString('Facts this business tracks', LeadSummaryContext::for($lead));
    }

    public function testKeyFactsRoundTripAndRender(): void
    {
        $schema = new JsonSchema()->generate(LeadSummaryUpdate::class);
        $this->assertSame('object', $schema['properties']['sections']['properties']['key_facts']['items']['type']);

        $update = new Deserializer()->fromJson((string) json_encode([
            'sections' => [
                'interest' => 'a white 2022 Tacoma',
                'current_state' => 'talking',
                'key_facts' => [['key' => 'Trade In', 'value' => '2015 Civic, 90k miles'], ['key' => 'lot', 'value' => 'null']],
            ],
            'operations' => [],
        ]), LeadSummaryUpdate::class);
        $this->assertInstanceOf(KeyFact::class, $update->sections->key_facts[0]);

        $lead = $this->makeLead();
        $this->leadMessage($lead, 'hello');
        $state = LeadSummaryFold::apply(new LeadSummaryState(), $update, LeadSummaryBatch::fromMessages([$this->leadMessage($lead, 'hi')]));
        LeadSummaryRepository::save($lead, $state);

        $rendered = LeadSummaryRepository::state($lead)->render();
        $this->assertStringContainsString('- Looking for: a white 2022 Tacoma', $rendered);
        $this->assertStringContainsString('- Trade In: 2015 Civic, 90k miles', $rendered);
        $this->assertStringNotContainsString('lot', $rendered, 'A "null" fact is dropped, not rendered');
        $this->assertSame([['key' => 'trade_in', 'value' => '2015 Civic, 90k miles']], LeadSummaryRepository::state($lead)->sections['key_facts'], 'Keys are stored snake_case');
    }
}
