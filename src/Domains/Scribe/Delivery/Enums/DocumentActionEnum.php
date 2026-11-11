<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Enums;

use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Scribe\Delivery\Support\QuoteSignatureRule;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * The company actions a customer-facing Scribe document is shared through (plan §5.2). The slug is the
 * ActionEngine action, pipeline and message-type slug; pagePath() is the `new_action_page_v4_config`
 * value, i.e. the frontend route the engagement link opens.
 */
enum DocumentActionEnum: string
{
    case VIEW_INVOICE = 'view-invoice';
    case VIEW_QUOTE = 'view-quote';
    case SIGN_SOW = 'sign-sow';

    public static function forDocument(Invoice|Quote $document): self
    {
        return $document instanceof Invoice ? self::VIEW_INVOICE : QuoteSignatureRule::actionFor($document);
    }

    public function title(): string
    {
        return match ($this) {
            self::VIEW_INVOICE => 'View Invoice',
            self::VIEW_QUOTE => 'View Quote',
            self::SIGN_SOW => 'Sign Statement of Work',
        };
    }

    public function pagePath(): string
    {
        return match ($this) {
            self::VIEW_INVOICE => 'invoice',
            self::VIEW_QUOTE => 'quote',
            self::SIGN_SOW => 'sow',
        };
    }

    /**
     * One stage set for all three, so a status the page or Scribe records always has a stage to land on.
     *
     * @return list<ActionStatusEnum>
     */
    public static function stages(): array
    {
        return [
            ActionStatusEnum::SENT,
            ActionStatusEnum::OPEN,
            ActionStatusEnum::DOWNLOADED,
            ActionStatusEnum::SUBMITTED,
            ActionStatusEnum::DECLINED,
            ActionStatusEnum::COMPLETED,
        ];
    }

    public function isQuoteAction(): bool
    {
        return $this !== self::VIEW_INVOICE;
    }
}
