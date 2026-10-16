<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

use Kanvas\Connectors\Intras\Mappers\LeadMapper;
use PHPUnit\Framework\TestCase;

class LeadMapperTest extends TestCase
{
    public function testMapsKnownLegacyStatusNamesToPipelineStages(): void
    {
        $this->assertSame('won', LeadMapper::stageForStatusName('GANADA'));
        $this->assertSame('lost', LeadMapper::stageForStatusName('PERDIDA'));
        $this->assertSame('won-plan', LeadMapper::stageForStatusName('GANADA PLAN'));
    }

    public function testMatchesStatusNamesRegardlessOfCaseOrSurroundingWhitespace(): void
    {
        $this->assertSame('cancelled', LeadMapper::stageForStatusName('  Cancelada  '));
    }

    /**
     * The previous id-based match sent every status it did not know to "drafting", so a status
     * added to `quotes_statuses` after the map was written silently mis-staged its quotes. An
     * unrecognised name now passes through, letting a stage of the same name match.
     */
    public function testPassesAnUnmappedStatusNameThroughInsteadOfDefaulting(): void
    {
        $this->assertSame('ESTADO NUEVO', LeadMapper::stageForStatusName('ESTADO NUEVO'));
    }

    public function testFallsBackToTheDefaultStageOnlyWhenTheCatalogHasNoName(): void
    {
        $this->assertSame(LeadMapper::DEFAULT_STAGE, LeadMapper::stageForStatusName(null));
        $this->assertSame(LeadMapper::DEFAULT_STAGE, LeadMapper::stageForStatusName('   '));
    }
}
