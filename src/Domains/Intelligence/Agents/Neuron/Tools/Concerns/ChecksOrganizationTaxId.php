<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Baka\Support\Str;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Scribe\Regional\DataTransferObject\TaxpayerLookup;
use Kanvas\Scribe\Regional\Services\TaxpayerLookupService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * The tax id an agent hands create/update_organization, checked before anything is written: the type is
 * stated or inferred (an ambiguous id is sent back with the question), it must fit its type, and a
 * Dominican RNC / cédula is looked up at DGII when the company has an e-CF provider to ask.
 *
 * Host tools use HasKanvasContext and ReportsToolOutcome.
 */
trait ChecksOrganizationTaxId
{
    /**
     * @return list<ToolProperty>
     */
    protected function taxIdProperties(): array
    {
        return [
            new ToolProperty(
                name: 'tax_id',
                type: PropertyType::STRING,
                description: 'The organization\'s tax id (RNC in the Dominican Republic, NIT, RUC, RFC, EIN…), with or without dashes.',
                required: false,
            ),
            new ToolProperty(
                name: 'tax_id_type',
                type: PropertyType::STRING,
                description: 'Which id tax_id is, one of: ' . implode(', ', TaxIdTypeEnum::values())
                    . '. Leave it out to infer it from the company\'s country and the number; pass it for a foreign '
                    . 'customer or vendor (e.g. us_ein, other).',
                required: false,
            ),
        ];
    }

    /**
     * @return array{tax_id: string, tax_id_type: TaxIdTypeEnum, dgii_legal_name: ?string, verification: string}|array<string, mixed>
     *         the checked id, or an invalidArgs() payload to return as-is (recognised by `success: false`)
     */
    protected function checkTaxId(?string $taxId, ?string $taxIdType): array
    {
        $normalized = Str::normalizeTaxId($taxId);
        if ($normalized === null) {
            return $this->invalidArgs(
                sprintf("'%s' is not a tax id. Nothing was saved; ask the user for the number.", (string) $taxId)
            );
        }

        $type = $this->taxIdTypeOrError($normalized, $taxIdType);
        if (is_array($type)) {
            return $type;
        }

        $checked = [
            'tax_id' => $normalized,
            'tax_id_type' => $type,
            'dgii_legal_name' => null,
            'verification' => 'not_applicable',
        ];

        if ($type !== TaxIdTypeEnum::DO_RNC && $type !== TaxIdTypeEnum::DO_CEDULA) {
            return $checked;
        }

        $lookup = new TaxpayerLookupService()->lookup($this->app, $this->company, $normalized);

        return match ($lookup->status) {
            TaxpayerLookup::NOT_REGISTERED => $this->invalidArgs(
                sprintf('DGII has no taxpayer registered under %s %s, so it was NOT saved.', $type->label(), $normalized),
                guidance: 'Confirm the number with the user (check_rnc verifies one), then call this tool again with the right one.',
            ),
            TaxpayerLookup::FOUND => [...$checked, 'dgii_legal_name' => $lookup->legalName, 'verification' => 'verified'],
            TaxpayerLookup::NO_PROVIDER => [...$checked, 'verification' => 'not_checked_no_ecf_provider'],
            default => [...$checked, 'verification' => 'lookup_failed'],
        };
    }

    /**
     * @param array<string, mixed>|null $checked
     * @return array<string, mixed>
     */
    protected function verificationResult(?array $checked): array
    {
        if ($checked === null) {
            return [];
        }

        return array_filter([
            'tax_id_verification' => $checked['verification'],
            'dgii_legal_name' => $checked['dgii_legal_name'],
        ], fn (mixed $value): bool => $value !== null);
    }

    private function taxIdTypeOrError(string $normalized, ?string $taxIdType): TaxIdTypeEnum|array
    {
        $codes = implode(', ', TaxIdTypeEnum::values());
        $requested = Str::trimToNull($taxIdType);

        if ($requested === null) {
            return TaxIdTypeEnum::infer($this->company->countryCode(), $normalized)
                ?? $this->invalidArgs(
                    sprintf("Could not tell which kind of tax id '%s' is, so nothing was saved.", $normalized),
                    guidance: $this->company->countryCode() === 'DO'
                        ? "A Dominican RNC has 9 digits and a cédula 11. Ask the user for the right number, or, for a foreign party, call again with tax_id_type ({$codes})."
                        : "Ask the user which it is and call again with tax_id_type, one of: {$codes}.",
                );
        }

        $type = TaxIdTypeEnum::tryFrom(strtolower($requested));
        if ($type === null) {
            return $this->invalidArgs("'{$requested}' is not a tax id type. Use one of: {$codes}.");
        }

        if (! $type->accepts($normalized)) {
            return $this->invalidArgs(
                sprintf("'%s' is not a valid %s (%s), so nothing was saved.", $normalized, $type->label(), $type->value),
                guidance: 'Ask the user for the right number or type.',
            );
        }

        return $type;
    }
}
