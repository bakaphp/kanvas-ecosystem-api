<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Contracts;

use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;

/**
 * A country's wire document: built and validated by its regime, mapped by that country's providers. Shared code
 * reads only these, so a regime's payload carries whatever its authority needs beside them.
 */
interface FiscalPayloadInterface
{
    public function documentType(): FiscalDocumentTypeInterface;

    /**
     * Empty before a number is taken: the document is validated numberless, so a document the authority would
     * reject never spends one.
     */
    public function number(): string;

    /**
     * Y-m-d.
     */
    public function issueDate(): string;

    /**
     * The send attempt's uuid, which the provider dedupes on.
     */
    public function systemRef(): string;

    /**
     * The document a note modifies, recorded as the fiscal row's relation.
     */
    public function reference(): ?EcfReference;
}
