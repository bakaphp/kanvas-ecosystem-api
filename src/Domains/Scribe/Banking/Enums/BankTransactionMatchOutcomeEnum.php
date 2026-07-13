<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Enums;

/**
 * What the matcher decided to do with one bank transaction.
 */
enum BankTransactionMatchOutcomeEnum: string
{
    /** Settled an open bill or invoice in full — the document is now PAID. */
    case SETTLED = 'settled';

    /** Applied against a document without clearing it. The document stays open with a reduced balance. */
    case SETTLED_PARTIAL = 'settled_partial';

    /** One payment cleared several of a single party's documents. All of them are now PAID. */
    case SETTLED_SPLIT = 'settled_split';

    /** A fee or interest — booked straight to its P&L account, nothing to match. */
    case RECOGNIZED = 'recognized';

    /**
     * An approved Expense already booked this exact movement (a card receipt ingested from PDF, say). We
     * link the two and post NOTHING — the entry is on the books once, and once is correct.
     */
    case ALREADY_BOOKED = 'already_booked';

    /**
     * Cash is booked; we just don't know what to call the other side. Parked in Suspense and queued for
     * someone to pick an account. NOT a draft document — the money already moved and that part is final.
     */
    case REVIEW = 'review';

    /** Several documents look equally plausible. Refused to guess; left for a human. */
    case AMBIGUOUS = 'ambiguous';

    /** Already settled or already posted — nothing to do. */
    case ALREADY_ACCOUNTED = 'already_accounted';
}
