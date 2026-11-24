<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Concerns;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Scribe\Delivery\Services\DocumentPageDataService;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * The EngageableInterface half every Scribe customer document shares. getGraphTypeName() stays on
 * the model: it is the HasLightHouseCache contract too.
 */
trait IsEngageableDocument
{
    public function getEngagementApp(): AppInterface
    {
        return $this->app;
    }

    public function getEngagementCompany(): CompanyInterface
    {
        return $this->company;
    }

    public function getEngagementChannelSlug(): string
    {
        return (string) $this->uuid;
    }

    /**
     * `invoice` or `quote`: the page's `kind` and the `scribe.{kind}.*` ledger prefix.
     */
    public function documentKind(): string
    {
        return $this instanceof Quote ? 'quote' : 'invoice';
    }

    public function documentNumber(): string
    {
        $number = $this instanceof Quote ? $this->quote_number : $this->invoice_number;

        return (string) ($number ?? $this->getId());
    }

    /**
     * @return array<string, mixed>
     */
    public function toTemplateData(): array
    {
        return new DocumentPageDataService()->for($this);
    }
}
