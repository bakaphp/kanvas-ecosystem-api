<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

use Kanvas\Connectors\Intras\Neuron\Tools\ScorecardTool;
use NeuronAI\Tools\HasRunKey;
use PHPUnit\Framework\TestCase;

/**
 * The parts of the tool that do not touch the flat tables: argument handling and the
 * self-description. Scoring against real data is exercised in the reporting integration tests.
 */
class ScorecardToolTest extends TestCase
{
    public function testRejectsAnUnknownEntity(): void
    {
        $result = new ScorecardTool()->__invoke('sucursal');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('ejecutivo', $result['message']);
    }

    public function testTheEntityArgumentIsCaseAndSpaceInsensitive(): void
    {
        $result = new ScorecardTool()->__invoke('  DEFINICION ');

        $this->assertSame('success', $result['status']);
    }

    /**
     * The letters do not exist as data anywhere in SIPGO, so the description has to say the tool
     * computes them. An agent told only "returns the classification" will present a computed
     * letter as a stored fact.
     */
    public function testDescribesEveryCardWithItsCriteriaAndCoverage(): void
    {
        $result = new ScorecardTool()->__invoke('definicion');

        $this->assertSame(
            ['ejecutivo_clasificacion', 'ejecutivo_potencialidad', 'empresa_clasificacion', 'empresa_potencialidad', 'evento_clasificacion'],
            array_keys($result['tarjetas'])
        );

        $ejecutivo = $result['tarjetas']['ejecutivo_clasificacion'];

        $this->assertSame(89.0, $ejecutivo['cobertura']);
        $this->assertCount(9, $ejecutivo['criterios']);
        $this->assertStringContainsString('no están almacenadas', $result['nota']);
    }

    /**
     * A criterion that cannot be measured is reported as such rather than dropped silently — the
     * reason is what tells a reader the letter is partial.
     */
    public function testAnUnscorableCriterionCarriesItsReason(): void
    {
        $criteria = new ScorecardTool()->__invoke('definicion')['tarjetas']['ejecutivo_clasificacion']['criterios'];

        $unscorable = array_values(array_filter(
            $criteria,
            static fn (array $criterion): bool => isset($criterion['no_calculable'])
        ));

        $this->assertCount(2, $unscorable);
        $this->assertStringContainsString('3% of registrations', $unscorable[0]['no_calculable']);
    }

    /**
     * Scoring is a per-entity read, so an agent classifying a list of companies makes one call
     * each. Keyed by tool name alone, the eleventh would abort the whole turn.
     */
    public function testTheRunBudgetIsKeyedByArgumentsSoBulkScoringIsPossible(): void
    {
        $tool = new ScorecardTool();

        $this->assertInstanceOf(HasRunKey::class, $tool);

        $first = $tool->setInputs(['entidad' => 'empresa', 'id' => 1])->getRunKey();
        $second = $tool->setInputs(['entidad' => 'empresa', 'id' => 2])->getRunKey();
        $repeat = $tool->setInputs(['entidad' => 'empresa', 'id' => 1])->getRunKey();

        $this->assertNotSame($first, $second, 'a different company must get its own budget');
        $this->assertSame($first, $repeat, 'an identical call must still be capped');
    }
}
