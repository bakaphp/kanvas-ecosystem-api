<?php

declare(strict_types=1);

namespace Tests\Approvals\Fixtures;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Override;
use RuntimeException;

/**
 * Stands in for the real sync lane (issue the invoice, push the bill). Counts its runs so a test can
 * prove the handler fired exactly once when two approvers close the same request at the same instant.
 */
class RecordingApprovalHandler implements ApprovalHandlerInterface
{
    public static bool $ran = false;
    public static int $runs = 0;
    public static bool $throw = false;

    public static function reset(): void
    {
        self::$ran = false;
        self::$runs = 0;
        self::$throw = false;
    }

    #[Override]
    public function handle(ApprovalRequest $request, ?UserInterface $approver): array
    {
        self::$ran = true;
        self::$runs++;

        if (self::$throw) {
            throw new RuntimeException('downstream exploded');
        }

        return ['reference' => 'acme-reference', 'pushed' => true];
    }
}
