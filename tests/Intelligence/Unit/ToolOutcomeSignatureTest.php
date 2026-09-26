<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCaseUnit;

/**
 * Coverage ratchet for `ReportsToolOutcome`'s two odd signatures.
 *
 * `denied()`, `failed()` and `invalidArgs()` take the message FIRST. `noop()` and `notFound()` take
 * `array $payload` first and the message second. That inconsistency reads as a typo at every call
 * site, and under `strict_types` a string in the first position is a hard `TypeError` — not a wrong
 * message, a dead turn.
 *
 * It shipped twice: `ReadHarnessPullRequestFeedbackTool` and `SyncHarnessPullRequestBranchTool` both
 * raised one whenever a model passed a job id that did not exist, which is the routine case rather
 * than an edge. **PHPStan at level 0 does not catch it**, and neither does the test suite, because
 * the branch only runs when a lookup misses. A grep is the only thing that does — same reasoning as
 * `NoUnguardedUrlFetchTest`.
 *
 * If this fires: move your message to the second argument and put any structured data in an array
 * first — `notFound(['job_id' => $id], 'No job ...')`.
 */
final class ToolOutcomeSignatureTest extends TestCaseUnit
{
    public function testNoopAndNotFoundAreNeverCalledWithAMessageFirst(): void
    {
        $base = base_path();
        $offenders = [];

        foreach (['src', 'app'] as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base . '/' . $dir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());

                // A quote or a concatenated variable straight after the paren is a message in the
                // payload slot. An array literal, a variable holding one, or a line break before the
                // first argument are all fine.
                if (preg_match('/->(?:noop|notFound)\(\s*[\'"]/', $code) !== 1) {
                    continue;
                }

                $offenders[] = ltrim(str_replace($base, '', $file->getPathname()), '/');
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'noop()/notFound() take `array $payload` first and the message second — unlike denied(), '
            . "failed() and invalidArgs(). A string in the first position is a TypeError at runtime:\n - "
            . implode("\n - ", $offenders)
        );
    }
}
