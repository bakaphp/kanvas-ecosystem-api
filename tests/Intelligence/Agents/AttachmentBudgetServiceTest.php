<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Services\AttachmentBudgetService;
use Tests\TestCase;

/**
 * The per-file caps left the aggregate unbounded: 200KB for inlined text, 50MB (SSRF_MAX_BYTES) for
 * everything else, and no limit on how many files one message carries. Enough of them put the
 * request past the provider's input ceiling, which answers 400 — not retryable — so the turn ends as
 * the generic "I ran into a hiccup" with the real cause invisible.
 */
class AttachmentBudgetServiceTest extends TestCase
{
    public function testAdmitsUntilTheBudgetIsSpent(): void
    {
        $budget = new AttachmentBudgetService(1000);

        $this->assertTrue($budget->admits('a.png', 600));
        $this->assertTrue($budget->admits('b.png', 400));
        $this->assertFalse($budget->admits('c.png', 1));
    }

    /** A file bigger than the whole budget is skipped, not allowed through as a special case. */
    public function testASingleOversizeAttachmentIsRejected(): void
    {
        $budget = new AttachmentBudgetService(1000);

        $this->assertFalse($budget->admits('huge.pdf', 5000));
    }

    /**
     * A later small attachment still fits after a big one was turned away — the budget tracks what
     * was actually spent, so one oversize file does not poison the rest of the message.
     */
    public function testARejectedAttachmentDoesNotConsumeBudget(): void
    {
        $budget = new AttachmentBudgetService(1000);

        $this->assertFalse($budget->admits('huge.pdf', 5000));
        $this->assertTrue($budget->admits('small.txt', 900));
    }

    public function testNothingSkippedMeansNoNote(): void
    {
        $budget = new AttachmentBudgetService(1000);
        $budget->admits('a.png', 10);

        $this->assertNull($budget->skippedNote());
    }

    /** Silence would leave the model answering as though it had read them. */
    public function testTheNoteNamesEverySkippedAttachment(): void
    {
        $budget = new AttachmentBudgetService(100);
        $budget->admits('first.pdf', 500);
        $budget->admits('second.pdf', 500);

        $note = $budget->skippedNote();

        $this->assertNotNull($note);
        $this->assertStringContainsString('first.pdf', $note);
        $this->assertStringContainsString('second.pdf', $note);
        $this->assertStringContainsString('2 attachment(s)', $note);
    }

    public function testTheDefaultBudgetLeavesRoomUnderTheProviderInlineCap(): void
    {
        $encoded = AttachmentBudgetService::MAX_TURN_BYTES * 4 / 3;

        $this->assertLessThan(20 * 1024 * 1024, $encoded);
    }
}
