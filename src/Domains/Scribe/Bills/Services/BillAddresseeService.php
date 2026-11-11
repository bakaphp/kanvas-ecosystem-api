<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Services;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Users\Models\Users;

/**
 * Whether a vendor invoice is addressed to the company booking it. A bill booked to the wrong entity is paid from
 * the wrong account, and people forward invoices for their other companies to the same inbox.
 *
 * A company's display name is often not the name vendors print (its legal name, a DBA, an old name), so the names
 * a person confirmed as theirs are remembered per company (`scribe_billing_names`) and match from then on.
 */
class BillAddresseeService
{
    public const string BILLING_NAMES_SETTING = 'scribe_billing_names';
    public const string METADATA_KEY = 'addressee_mismatch';
    public const string CLAIMED_SAME_COMPANY = 'same_company';

    private const array LEGAL_SUFFIXES = [
        'inc', 'incorporated', 'llc', 'ltd', 'limited', 'corp', 'corporation', 'co', 'company', 'srl', 'sa', 'sas',
        'sl', 'eirl', 'sde', 'rl', 'de', 'cv', 'plc', 'gmbh', 'pllc', 'lp', 'llp',
    ];

    public function isAddressedTo(Companies $company, string $billedTo, ?string $billedToTaxId = null): bool
    {
        $taxId = Str::trimToNull(Str::digitsOnly((string) $billedToTaxId));
        $companyTaxId = Str::trimToNull(Str::digitsOnly((string) $company->get(EcfConfigurationEnum::ECF_ISSUER_TAX_ID->value)));
        if ($taxId !== null && $taxId === $companyTaxId) {
            return true;
        }

        $wanted = self::normalize($billedTo);
        if ($wanted === '') {
            return false;
        }

        foreach ($this->names($company) as $name) {
            if (self::normalize($name) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Another company of the same person the invoice is addressed to, so the agent can say whose books it belongs in.
     */
    public function otherCompanyOf(Users $user, Companies $current, string $billedTo): ?Companies
    {
        $wanted = self::normalize($billedTo);
        if ($wanted === '') {
            return null;
        }

        foreach ($user->companies()->get() as $company) {
            if ($company->getId() !== $current->getId() && $this->isAddressedTo($company, $billedTo)) {
                return $company;
            }
        }

        return null;
    }

    /**
     * A person approved a bill whose bill-to was claimed to be this company under another name: from now on that
     * name is this company's.
     */
    public function rememberApproved(Bill $bill): void
    {
        $mismatch = ($bill->metadata ?? [])[self::METADATA_KEY] ?? null;

        if (is_array($mismatch) && ($mismatch['claimed'] ?? null) === self::CLAIMED_SAME_COMPANY && $bill->company instanceof Companies) {
            $this->remember($bill->company, (string) ($mismatch['billed_to'] ?? ''));
        }
    }

    public function remember(Companies $company, string $billedTo): void
    {
        $name = Str::trimToNull($billedTo);
        if ($name === null || $this->isAddressedTo($company, $name)) {
            return;
        }

        $company->set(self::BILLING_NAMES_SETTING, [...$this->storedNames($company), $name]);
    }

    /**
     * @return list<string>
     */
    private function names(Companies $company): array
    {
        return [(string) $company->name, ...$this->storedNames($company)];
    }

    /**
     * @return list<string>
     */
    private function storedNames(Companies $company): array
    {
        $stored = $company->get(self::BILLING_NAMES_SETTING);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    /**
     * "Kyanvasu, Inc." and "KYANVASU INC" are the same addressee; legal-form suffixes and punctuation say nothing.
     */
    private static function normalize(string $name): string
    {
        // Dots go first so "S.R.L." is the suffix "srl", not three loose letters.
        $words = preg_split('/[^a-z0-9]+/', str_replace('.', '', strtolower(Str::ascii($name)))) ?: [];
        $words = array_values(array_filter($words, fn (string $word): bool => $word !== '' && ! in_array($word, self::LEGAL_SUFFIXES, true)));

        return implode(' ', $words);
    }
}
