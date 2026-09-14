<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Contracts;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Models\ApprovalRequest;

/**
 * The synchronous half of an approval's side effects — issue the invoice, push the bill, publish the
 * product. Runs in the caller's process so an LLM tool or a mutation gets a real answer about whether
 * the downstream write actually landed; the workflow lane is async and cannot report that back.
 *
 * The returned array is passed straight to the caller and into the fired workflow's params.
 */
interface ApprovalHandlerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function handle(ApprovalRequest $request, ?UserInterface $approver): array;
}
