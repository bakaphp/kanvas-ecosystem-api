<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

class ValidateCorporateFieldsAction
{
    public function __construct(
        protected readonly array $fields,
    ) {
    }

    public function execute(): ?string
    {
        $rnc = trim((string) ($this->fields['rnc'] ?? ''));

        if ($rnc === '') {
            return 'RNC is required';
        }

        // DR RNC accepts 9 digits (companies) or 11 digits (individuals); strip separators.
        $rncDigits = preg_replace('/\D/', '', $rnc) ?? '';

        if (! in_array(strlen($rncDigits), [9, 11], true)) {
            return 'RNC must be 9 or 11 digits';
        }

        $legalName = trim((string) ($this->fields['legal_name'] ?? ''));

        if ($legalName === '') {
            return 'Legal name is required';
        }

        $contactEmail = trim((string) ($this->fields['contact_email'] ?? ''));

        if ($contactEmail === '') {
            return 'Contact email is required';
        }

        if (! filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            return 'Contact email is not a valid email address';
        }

        return null;
    }
}
