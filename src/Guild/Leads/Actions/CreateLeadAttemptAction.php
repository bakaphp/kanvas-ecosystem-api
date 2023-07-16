<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Leads\Models\LeadAttempt;

class CreateLeadAttemptAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected readonly array $request,
        protected readonly array $headers,
        protected readonly CompanyInterface $company,
        protected readonly string $ip,
        protected readonly string $source
    ) {
    }

    /**
     *  @psalm-suppress MixedReturnStatement
     */
    public function execute(): LeadAttempt
    {
        return LeadAttempt::create([
            'companies_id' => $this->company->getId(),
            'header' => $this->headers,
            'request' => $this->request,
            'ip' => $this->ip,
            'source' => $this->source,
            'public_key' => $this->request['public_key'] ?? null,
            'processed' => 0,
        ]);
    }
}
