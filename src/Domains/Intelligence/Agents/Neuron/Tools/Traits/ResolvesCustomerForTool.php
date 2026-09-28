<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationVendorMatcherService;

/**
 * Turns the customer NAME an agent was given into a Guild Organization, or into the structured
 * refusal that tells the model to disambiguate. Requires HasKanvasContext ($app, $company).
 *
 * The matcher deliberately refuses a close call rather than picking one, because this decides which
 * customer gets invoiced/quoted — so every tool that writes an AR document has to handle "matched /
 * ambiguous / none" the same way, and that shape lives here rather than in each of them.
 */
trait ResolvesCustomerForTool
{
    use HasKanvasContext;

    /**
     * @param string $disambiguationHint what the model should do next when the name is ambiguous
     *
     * @return Organization|array{reason: string, message: string}
     */
    protected function resolveCustomerOrError(
        string $customerName,
        string $disambiguationHint = 'Call find_customer and confirm the right one with the user.',
    ): Organization|array {
        $match = OrganizationVendorMatcherService::match($this->app, $this->company, $customerName);

        if ($match->isMatched()) {
            /** @var Organization $customer */
            $customer = $match->organization;

            return $customer;
        }

        if ($match->candidates === []) {
            return [
                'reason' => 'customer_not_found',
                'message' => "No customer organization matching \"{$customerName}\" for this app/company.",
            ];
        }

        return [
            'reason' => 'customer_ambiguous',
            'message' => "\"{$customerName}\" could match more than one customer: "
                . implode(', ', array_map(static fn (Organization $o): string => $o->name, $match->candidates))
                . '. ' . $disambiguationHint,
        ];
    }
}
