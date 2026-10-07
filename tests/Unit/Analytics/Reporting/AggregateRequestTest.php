<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics\Reporting;

use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
use Kanvas\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * The alias and the function name are interpolated into a `DB::raw` select list, and both can
 * originate from an agent — `run_report` hands LLM-supplied input straight to `fromInput()`.
 * `ReportQueryService` validates column names against the definition; everything else is stopped
 * here, so these two guards are the whole boundary between a model's output and generated SQL.
 */
class AggregateRequestTest extends TestCase
{
    public function testCompilesAggregatesAndDefaultsTheAlias(): void
    {
        $request = AggregateRequest::fromInput([
            ['function' => 'sum', 'column' => 'monto'],
            ['function' => 'COUNT'],
        ]);

        $this->assertSame(['sum_0' => ['SUM', 'monto'], 'count_1' => ['COUNT', null]], $request->aggregates);
    }

    public function testTheFunctionNameIsCaseAndSpaceInsensitive(): void
    {
        $request = AggregateRequest::fromInput([['function' => '  avg ', 'column' => 'precio', 'alias' => 'media']]);

        $this->assertSame(['media' => ['AVG', 'precio']], $request->aggregates);
    }

    /**
     * The allow-list, not an escape. A function name reaches the select list verbatim.
     */
    public function testRejectsAFunctionOutsideTheAllowList(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported aggregate "SLEEP(5)"');

        AggregateRequest::fromInput([['function' => 'SLEEP(5)', 'column' => 'id']]);
    }

    /**
     * An alias is interpolated into `... AS <alias>`, so anything but a plain identifier is a
     * way into the query.
     */
    public function testRejectsAnAliasThatIsNotAPlainIdentifier(): void
    {
        foreach (['n; DROP TABLE x', 'total AS y', '1total', 'Total', 'a b', ''] as $alias) {
            try {
                new AggregateRequest(aggregates: [$alias => ['COUNT', null]]);
                $this->fail(sprintf('alias "%s" should have been rejected', $alias));
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Invalid aggregate alias', $e->getMessage());
            }
        }
    }

    public function testAcceptsALowercaseSnakeCaseAlias(): void
    {
        $request = new AggregateRequest(aggregates: ['total_2025' => ['COUNT', null]]);

        $this->assertSame(['total_2025' => ['COUNT', null]], $request->aggregates);
    }

    public function testAnAliasIsCappedAtSixtyFourCharacters(): void
    {
        $this->expectException(ValidationException::class);

        new AggregateRequest(aggregates: ['a' . str_repeat('b', 64) => ['COUNT', null]]);
    }

    /**
     * COUNT is the only function that means something without a column; the rest would render
     * as `SUM()` and fail at the database instead of here.
     */
    public function testEveryFunctionButCountNeedsAColumn(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('SUM needs a column.');

        AggregateRequest::fromInput([['function' => 'SUM']]);
    }

    public function testAnEmptyColumnIsTreatedAsAbsent(): void
    {
        $this->expectException(ValidationException::class);

        AggregateRequest::fromInput([['function' => 'MAX', 'column' => '']]);
    }

    /**
     * Not a SQL function — the service renders it as `COUNT(DISTINCT col)`. It is in the
     * allow-list because the grain forces it: counting rows on `inscripcion` counts
     * registrations, not people.
     */
    public function testCountDistinctIsAllowedAndCarriesItsColumn(): void
    {
        $request = AggregateRequest::fromInput([
            ['function' => 'COUNT_DISTINCT', 'column' => 'peoples_id', 'alias' => 'personas'],
        ]);

        $this->assertSame(['personas' => ['COUNT_DISTINCT', 'peoples_id']], $request->aggregates);
    }
}
