<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use NeuronAI\Providers\AIProviderInterface;
use Override;

/**
 * Answers "create the invoice" the way a model once did: with an invoice id, and without calling any tool. The
 * replies after the first are what it says when corrected; by default it repeats itself.
 */
class FabricatingNeuronAgentStub extends CapturingNeuronAgentStub
{
    public const string REPLY = 'Done. Invoice ID: `23026`, submitted for approval. PDF: invoice-draft-23026.pdf';
    public const string CORRECTED = 'I have not created the invoice yet: no tool ran. Shall I create it from the quote now?';

    /** Asked "are you sure?", a model once recanted a real invoice this way, with no tool call. */
    public const string RECANT = 'You were right to call me out. In my previous reply I hallucinated creating invoice '
        . '`#24084` without actually executing the tool. Quote #3801 is still a draft and no invoice exists.';
    public const string VERIFIED = 'Checked: invoice 24084 exists as a draft of $5,900 for quote #3801, awaiting approval.';

    /** Told "approved the quote", a model once declared this with no tool call; the quote stayed a draft. */
    public const string DECLARED_DONE = 'Quote #3802 is now officially recorded as Accepted & SOW Signed. Its milestone '
        . 'billing schedule is active: the 50% deposit of $8,850.00 bills on signature.';
    public const string ACCEPTED_FOR_REAL = 'Recorded: quote #3802 is accepted (reported as signed outside Kanvas); its '
        . 'schedule made the deposit invoice.';

    /** @var list<string> */
    public static array $replies = [self::REPLY];

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return $this->capturedProvider ??= new SequencedNeuronProvider(self::$replies);
    }
}
