<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\NervousSystem\Plan\Support\VerificationVerdict;
use Tests\TestCaseUnit;

/**
 * The verdict was once "the reply contains VERIFIED", which "NOT VERIFIED" satisfied. A structured
 * reply closes that hole only if its substance is checked too: a pass with no checks, a failed check,
 * or no evidence is the same false positive in a different shape.
 */
final class VerificationVerdictTest extends TestCaseUnit
{
    public function testProseContainingTheOldTokenIsNotAPass(): void
    {
        foreach (['VERIFIED', 'NOT VERIFIED', 'UNVERIFIED', 'I could not verify this'] as $prose) {
            $this->assertFalse(VerificationVerdict::fromResponse($prose)->passed, $prose);
        }
    }

    public function testACompleteStructuredPassPasses(): void
    {
        $verdict = VerificationVerdict::fromResponse($this->verdictJson('pass', [
            ['criterion' => 'The PR exists', 'passed' => true, 'evidence' => 'PR #42 is open on main'],
            ['criterion' => 'CI is green', 'passed' => true, 'evidence' => 'Run 918 succeeded'],
        ]));

        $this->assertTrue($verdict->passed);
        $this->assertCount(2, $verdict->checks);
    }

    public function testAFencedJsonAnswerIsRead(): void
    {
        $fenced = "Here is my verdict:\n```json\n" . $this->verdictJson('pass', [
            ['criterion' => 'File exists', 'passed' => true, 'evidence' => 'Listed in the repo tree'],
        ]) . "\n```";

        $this->assertTrue(VerificationVerdict::fromResponse($fenced)->passed);
    }

    public function testAPassWithNoChecksFails(): void
    {
        $verdict = VerificationVerdict::fromResponse($this->verdictJson('pass', []));

        $this->assertFalse($verdict->passed);
        $this->assertStringContainsString('no checks', $verdict->reason);
    }

    public function testAPassWithAFailedCheckFails(): void
    {
        $verdict = VerificationVerdict::fromResponse($this->verdictJson('pass', [
            ['criterion' => 'The PR exists', 'passed' => true, 'evidence' => 'PR #42'],
            ['criterion' => 'CI is green', 'passed' => false, 'evidence' => 'Run 918 failed'],
        ]));

        $this->assertFalse($verdict->passed);
        $this->assertStringContainsString('CI is green', $verdict->reason);
    }

    public function testAPassWithoutEvidenceFails(): void
    {
        $verdict = VerificationVerdict::fromResponse($this->verdictJson('pass', [
            ['criterion' => 'The PR exists', 'passed' => true, 'evidence' => ''],
        ]));

        $this->assertFalse($verdict->passed);
        $this->assertStringContainsString('no evidence', $verdict->reason);
    }

    public function testACheckWithoutACriterionOrABooleanOutcomeFails(): void
    {
        $this->assertFalse(VerificationVerdict::fromResponse($this->verdictJson('pass', [
            ['criterion' => '', 'passed' => true, 'evidence' => 'something'],
        ]))->passed);

        $this->assertFalse(VerificationVerdict::fromResponse($this->verdictJson('pass', [
            ['criterion' => 'The PR exists', 'passed' => 'yes', 'evidence' => 'PR #42'],
        ]))->passed);
    }

    public function testAnUnknownVerdictWordFails(): void
    {
        $this->assertFalse(VerificationVerdict::fromResponse($this->verdictJson('verified', [
            ['criterion' => 'The PR exists', 'passed' => true, 'evidence' => 'PR #42'],
        ]))->passed);
    }

    public function testAnExplicitFailFailsEvenWhenEveryCheckHeld(): void
    {
        $verdict = VerificationVerdict::fromResponse($this->verdictJson('fail', [
            ['criterion' => 'The PR exists', 'passed' => true, 'evidence' => 'PR #42'],
        ]));

        $this->assertFalse($verdict->passed);
        $this->assertCount(1, $verdict->checks, 'the checks are kept for the reviewer');
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function verdictJson(string $verdict, array $checks): string
    {
        return json_encode(['verdict' => $verdict, 'checks' => $checks], JSON_THROW_ON_ERROR);
    }
}
