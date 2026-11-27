<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Company\CompanyBrainAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddLeadNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddOrganizationNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddPersonNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreatePersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindPeopleBulkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadRefTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListOrganizationPeopleTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListPeopleTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\PastOpportunitiesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReadLeadActivityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReadOrganizationActivityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReadPersonActivityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadCustomFieldsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadDescriptionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdatePersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\CreateProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ListAvailableProductsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ProductDetailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\VariantDetailTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Tests\Traits\MakesCompanyBrain;
use Tests\Traits\MakesEmployees;

/**
 * The records Company Brain works with itself: it can always look, and it creates and notes only for
 * a person who is there.
 */
final class CompanyBrainRecordToolsTest extends TestCase
{
    use DatabaseTransactions;
    use MakesCompanyBrain;
    use MakesEmployees;

    private const array READS = [
        LeadRefTool::class,
        ReadLeadActivityTool::class,
        PastOpportunitiesTool::class,
        FindPersonTool::class,
        FindPeopleBulkTool::class,
        GetPersonTool::class,
        ListPeopleTool::class,
        ReadPersonActivityTool::class,
        GetOrganizationTool::class,
        ListOrganizationPeopleTool::class,
        ReadOrganizationActivityTool::class,
        InventorySearchTool::class,
        ProductDetailTool::class,
        VariantDetailTool::class,
        ListAvailableProductsTool::class,
    ];

    /**
     * Named by the description of a tool the brain holds, and not held. `find_customer` belongs to
     * accounting, which a company may not run. `find_crm_records` is a name update_lead still points
     * at that no tool carries. A model that reaches for either is answered by the unknown-tool
     * recovery with what it does hold.
     */
    private const array NAMED_BUT_NOT_HELD = ['find_crm_records', 'find_customer'];

    private const array WRITES = [
        CreateLeadTool::class,
        CreatePersonTool::class,
        CreateOrganizationTool::class,
        UpdateLeadTool::class,
        UpdateLeadDescriptionTool::class,
        SetLeadStatusTool::class,
        SetLeadCustomFieldsTool::class,
        UpdatePersonTool::class,
        UpdateOrganizationTool::class,
        AddLeadNoteTool::class,
        AddPersonNoteTool::class,
        AddOrganizationNoteTool::class,
    ];

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow', 'crm'];

    private Users $admin;
    private Users $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompanyBrain();
        $this->admin = $this->makeAdmin();
        $this->employee = $this->makeEmployee();
    }

    public function testAPersonAtTheKeyboardCanLookCreateUpdateAndNote(): void
    {
        $held = $this->toolClasses($this->brainHandler($this->admin));

        foreach ([...self::READS, ...self::WRITES] as $class) {
            $this->assertContains($class, $held);
        }
    }

    public function testAnOrdinaryEmployeeGetsTheSameSet(): void
    {
        $this->assertFalse($this->employee->isAdmin());

        $held = $this->toolClasses($this->brainHandler($this->employee));

        foreach ([...self::READS, ...self::WRITES] as $class) {
            $this->assertContains($class, $held);
        }
    }

    public function testAnUnattendedTurnOnlyLooks(): void
    {
        $held = $this->toolClasses($this->brainHandler($this->admin, unattended: true));

        foreach (self::READS as $class) {
            $this->assertContains($class, $held);
        }
        foreach (self::WRITES as $class) {
            $this->assertNotContains($class, $held);
        }
    }

    public function testTheCatalogIsReadOnly(): void
    {
        $this->assertNotContains(CreateProductTool::class, $this->toolClasses($this->brainHandler($this->admin)));
    }

    public function testACatalogGrantStillAddsWhatTheBrainDoesNotHoldItself(): void
    {
        $this->grantFromCatalog($this->brain, [CreateProductTool::class]);

        $this->assertContains(CreateProductTool::class, $this->toolClasses($this->brainHandler($this->admin)));
    }

    public function testACatalogGrantOfTheSameToolIsNotDoubled(): void
    {
        $this->grantFromCatalog($this->brain, [CreatePersonTool::class]);

        $held = $this->toolClasses($this->brainHandler($this->admin));

        $this->assertCount(1, array_keys($held, CreatePersonTool::class, true));
    }

    public function testItCreatesUpdatesAndNotesAPersonAsItself(): void
    {
        $handler = $this->brainHandler($this->employee);

        $created = $this->tool($handler, 'create_person')(
            firstname: 'Ana',
            lastname: 'Perez ' . fake()->unique()->lexify('?????'),
            email: fake()->unique()->safeEmail(),
        );

        $person = People::getById((int) $created['person_id']);
        $this->assertSame($this->company->getId(), (int) $person->companies_id);
        // Stamped as the brain's own user, not as whoever asked.
        $this->assertSame((int) $this->brain->user_id, (int) $person->users_id);

        $updated = $this->tool($handler, 'update_person')(person_id: $person->getId(), firstname: 'Ana Maria');

        $this->assertArrayNotHasKey('error', $updated);
        $this->assertSame('Ana Maria', $person->refresh()->firstname);

        $noted = $this->tool($handler, 'add_person_note')(
            person_id: $person->getId(),
            note: 'Asked for a quote on the phone.',
        );

        $this->assertArrayNotHasKey('error', $noted);
    }

    public function testTheOnlyToolsNamedAndNotHeldAreTheOnesLeftOutOnPurpose(): void
    {
        $tools = $this->brainTools($this->brainHandler($this->admin));
        $names = array_map(fn ($tool): string => $tool->getName(), $tools);
        $prose = '';
        foreach ($tools as $tool) {
            if (! in_array($tool::class, [...self::READS, ...self::WRITES], true)) {
                continue;
            }

            $prose .= ' ' . $tool->getDescription();
            foreach ($tool->getProperties() as $property) {
                $prose .= ' ' . $property->getDescription();
            }
        }

        preg_match_all(
            '/\b(?:get|search|find|create|update|set|add|send|list|upload|read|link|merge|tag)_[a-z0-9_]+\b/',
            $prose,
            $mentioned,
        );

        $dangling = array_values(array_diff(array_unique($mentioned[0]), $names));
        sort($dangling);

        $this->assertSame(self::NAMED_BUT_NOT_HELD, $dangling);
    }

    public function testInstructionsSayToLookFirstAndWhatANewLeadSetsOff(): void
    {
        $handler = new CompanyBrainAgent();
        $handler->setConfiguration(agent: $this->brain, user: $this->admin);

        $instructions = $handler->instructions();

        $this->assertStringContainsString('## Leads, people and organizations', $instructions);
        $this->assertStringContainsString('Look first', $instructions);
        $this->assertStringContainsString('contacted automatically', $instructions);
    }

    /**
     * @return list<class-string>
     */
    private function toolClasses(CompanyBrainAgent $handler): array
    {
        return array_map(fn (object $tool): string => $tool::class, $this->brainTools($handler));
    }
}
