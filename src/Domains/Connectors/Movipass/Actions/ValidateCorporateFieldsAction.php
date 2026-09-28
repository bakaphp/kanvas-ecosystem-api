<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

/**
 * Shape rules only. Whether a key is required at all is receiver configuration
 * (`Field::requiredFor()`), so a key that is absent here is simply not checked.
 */
class ValidateCorporateFieldsAction
{
    public function __construct(
        protected readonly array $fields,
    ) {
    }

    public function execute(): ?string
    {
        // DR RNC accepts 9 digits (companies) or 11 digits (individuals); strip separators.
        $rnc = trim((string) ($this->fields['rnc'] ?? ''));

        if ($rnc !== '' && ! in_array(strlen((string) preg_replace('/\D/', '', $rnc)), [9, 11], true)) {
            return 'RNC must be 9 or 11 digits';
        }

        $contactEmail = trim((string) ($this->fields['contact_email'] ?? ''));

        if ($contactEmail !== '' && ! filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            return 'Contact email is not a valid email address';
        }

        return null;
    }
}
