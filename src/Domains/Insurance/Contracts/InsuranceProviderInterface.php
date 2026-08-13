<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Workflow\Enums\IntegrationsEnum;

/**
 * Core contract every insurer implements.
 *
 * Quoting is deliberately Order-free: the graph exposes it as a read so a user can
 * compare insurers without persisting anything. The Order appears at contract time,
 * stamped via InsuranceCustomFieldEnum, and every step past that is driven by
 * workflow activities off the order's status — the same shape as the other Movipass
 * verticals, not one mutation per insurer operation.
 *
 * Capabilities beyond quoting are opt-in — see InspectionProviderInterface,
 * PaymentLinkProviderInterface, PolicyProviderInterface, CatalogProviderInterface.
 */
interface InsuranceProviderInterface
{
    /**
     * Provider identifier — matches the value stamped on the Order under
     * InsuranceCustomFieldEnum::PROVIDER and the container binding suffix.
     */
    public function name(): string;

    /**
     * The connector this provider talks through, so a single generic activity can
     * call executeIntegration() for any insurer instead of one activity per brand.
     */
    public function integration(): IntegrationsEnum;

    public function quote(InsuranceQuoteRequest $request): QuoteResult;

    public function getQuote(string $quoteNumber): QuoteResult;
}
