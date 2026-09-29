<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics\Reporting;

use Kanvas\Analytics\Reporting\Support\ReportRefreshSuppressor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Suppression has to be impossible to leave on by accident: a long-running Octane worker that
 * kept it enabled would stop refreshing reports for every later request, silently.
 */
class ReportRefreshSuppressorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ReportRefreshSuppressor::reset();
    }

    public function testRefreshIsDispatchedByDefault(): void
    {
        $this->assertFalse(ReportRefreshSuppressor::isSuppressed());
    }

    public function testDispatchIsSuppressedOnlyInsideTheCallback(): void
    {
        $inside = ReportRefreshSuppressor::while(fn () => ReportRefreshSuppressor::isSuppressed());

        $this->assertTrue($inside);
        $this->assertFalse(ReportRefreshSuppressor::isSuppressed(), 'must restore on the way out');
    }

    /**
     * The case that matters: a bulk import that throws halfway must not leave reporting disabled
     * for everything that runs after it on the same worker.
     */
    public function testSuppressionIsRestoredWhenTheCallbackThrows(): void
    {
        try {
            ReportRefreshSuppressor::while(function (): void {
                throw new RuntimeException('import blew up');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(ReportRefreshSuppressor::isSuppressed());
    }

    /**
     * Nesting restores the previous value rather than hardcoding false, so an inner block
     * finishing cannot re-enable dispatch for the outer bulk operation.
     */
    public function testNestingDoesNotReEnableDispatchForTheOuterBlock(): void
    {
        $stillSuppressed = ReportRefreshSuppressor::while(function () {
            ReportRefreshSuppressor::while(fn () => null);

            return ReportRefreshSuppressor::isSuppressed();
        });

        $this->assertTrue($stillSuppressed);
        $this->assertFalse(ReportRefreshSuppressor::isSuppressed());
    }

    public function testTheCallbackResultIsReturned(): void
    {
        $this->assertSame('rows', ReportRefreshSuppressor::while(fn () => 'rows'));
    }
}
