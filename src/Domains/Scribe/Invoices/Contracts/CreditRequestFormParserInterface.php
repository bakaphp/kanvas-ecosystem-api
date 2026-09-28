<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Contracts;

/**
 * Parses a client's "credit request" document into the fields create_ar_credit_memo needs.
 *
 * Every client's Sales team uses its own document — different fields, different layout — so there is
 * no single universal shape here. Each client's own parser lives under its own Connectors/{Client}/
 * module (e.g. Kanvas\Connectors\Nzxt\Services\CreditRequestFormParserService) and implements this
 * contract; the agent tool depends only on the interface, never a specific client's parser.
 */
interface CreditRequestFormParserInterface
{
    /**
     * @return array{customer_name: string, region: ?string, tenant: ?string, request_reference_no: string, lines: list<array{control_account_number: string, description: string, amount: float}>, total: float}
     */
    public function parse(string $localFilePath): array;
}
