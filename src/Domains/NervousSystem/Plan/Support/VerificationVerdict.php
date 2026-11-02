<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Support;

use Kanvas\Intelligence\Agents\Helpers\ChatHelper;

/**
 * The verifier's answer, judged on its substance.
 *
 * A reply that merely contains the word VERIFIED is not a pass: "NOT VERIFIED" contains it too. A
 * structured reply that only has to parse would move the same hole into a JSON field. So a pass has
 * to carry the work: at least one check, each naming the criterion it tested, whether it held, and the
 * evidence read — and every check held. Anything short of that is a fail, with the reason kept so the
 * person who reviews a blocked plan can see what the verifier did not do.
 */
final readonly class VerificationVerdict
{
    public const string PASS = 'pass';
    public const string FAIL = 'fail';

    /**
     * @param list<array{criterion: string, passed: bool, evidence: string}> $checks
     */
    private function __construct(
        public bool $passed,
        public string $reason,
        public array $checks,
    ) {
    }

    public static function fromResponse(string $response): self
    {
        $envelope = ChatHelper::extractJsonEnvelope($response) ?? json_decode(trim($response), true);

        if (! is_array($envelope)) {
            return self::fail('The verifier did not answer with a structured verdict.');
        }

        $verdict = strtolower(trim((string) ($envelope['verdict'] ?? '')));

        if (! in_array($verdict, [self::PASS, self::FAIL], true)) {
            return self::fail('The verdict must be exactly "pass" or "fail".');
        }

        $rawChecks = $envelope['checks'] ?? null;

        if (! is_array($rawChecks) || $rawChecks === []) {
            return self::fail('A verdict with no checks verifies nothing.');
        }

        $checks = [];
        foreach (array_values($rawChecks) as $index => $check) {
            if (! is_array($check)) {
                return self::fail(sprintf('Check %d is not an object.', $index + 1));
            }

            $criterion = trim((string) ($check['criterion'] ?? ''));
            $evidence = trim((string) ($check['evidence'] ?? ''));
            $held = $check['passed'] ?? null;

            if ($criterion === '' || ! is_bool($held)) {
                return self::fail(sprintf('Check %d does not name a criterion and a boolean outcome.', $index + 1));
            }

            if ($evidence === '') {
                return self::fail(sprintf('Check "%s" carries no evidence.', $criterion));
            }

            $checks[] = ['criterion' => $criterion, 'passed' => $held, 'evidence' => $evidence];
        }

        $failed = array_values(array_filter($checks, static fn (array $check): bool => ! $check['passed']));

        if ($verdict === self::FAIL) {
            return new self(false, 'The verifier reported a fail.', $checks);
        }

        if ($failed !== []) {
            return new self(
                false,
                'A pass with a failed check is not a pass: ' . $failed[0]['criterion'],
                $checks,
            );
        }

        return new self(true, 'Every check held.', $checks);
    }

    private static function fail(string $reason): self
    {
        return new self(false, $reason, []);
    }
}
