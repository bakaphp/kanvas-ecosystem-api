<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics\Reporting;

use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class ReportFilterTest extends TestCase
{
    /**
     * `run_report` has to declare `value` as a plain string — Gemini rejects a union type, and an
     * untyped array is the shape that 400s the whole tool list. So an `IN` arrives as one string
     * and has to be split here, or it reaches the query builder as a single value and matches
     * nothing. That failure is silent: an empty result set is indistinguishable from a real one.
     */
    public function testSplitsACommaSeparatedListForIn(): void
    {
        $filter = ReportFilter::fromArray([
            'column' => 'tipo_inscripcion',
            'operator' => 'IN',
            'value' => 'CONFIRMADO, CONFIRMADO PLAN,PROGRAMA',
        ]);

        $this->assertSame(['CONFIRMADO', 'CONFIRMADO PLAN', 'PROGRAMA'], $filter->value);
    }

    public function testSplitsBetweenIntoItsTwoBounds(): void
    {
        $filter = ReportFilter::fromArray([
            'column' => 'fecha_inicio',
            'operator' => 'BETWEEN',
            'value' => '2025-01-01, 2025-12-31',
        ]);

        $this->assertSame(['2025-01-01', '2025-12-31'], $filter->value);
    }

    /**
     * The reason splitting is scoped to list operators rather than applied to every string: legacy
     * company names carry commas, and cutting "Banco Popular, S.A." in half would quietly filter
     * on a company that does not exist.
     */
    public function testLeavesACommaInsideAnEqualsValueAlone(): void
    {
        $filter = ReportFilter::fromArray([
            'column' => 'empresa',
            'operator' => '=',
            'value' => 'Banco Popular, S.A.',
        ]);

        $this->assertSame('Banco Popular, S.A.', $filter->value);
    }

    public function testLeavesAnArrayValueUntouched(): void
    {
        $filter = ReportFilter::fromArray([
            'column' => 'tipo_inscripcion',
            'operator' => 'IN',
            'value' => ['CONFIRMADO', 'PROGRAMA'],
        ]);

        $this->assertSame(['CONFIRMADO', 'PROGRAMA'], $filter->value);
    }

    public function testDropsEmptyEntriesFromATrailingComma(): void
    {
        $filter = ReportFilter::fromArray([
            'column' => 'clase',
            'operator' => 'IN',
            'value' => 'SEMINARIO, ,',
        ]);

        $this->assertSame(['SEMINARIO'], $filter->value);
    }

    public function testUppercasesAndTrimsTheOperator(): void
    {
        $this->assertSame('NOT IN', ReportFilter::fromArray([
            'column' => 'clase',
            'operator' => ' not in ',
            'value' => 'TALLER',
        ])->operator);
    }

    public function testRejectsAnOperatorOutsideTheSupportedSet(): void
    {
        $this->expectException(ValidationException::class);

        ReportFilter::fromArray(['column' => 'clase', 'operator' => 'REGEXP', 'value' => '.*']);
    }

    public function testNullOperatorsNeedNoValue(): void
    {
        $this->assertFalse(ReportFilter::fromArray(['column' => 'nivel', 'operator' => 'IS NULL'])->needsValue());
        $this->assertTrue(ReportFilter::fromArray(['column' => 'nivel', 'operator' => '='])->needsValue());
    }
}
