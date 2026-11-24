<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Client;
use Kanvas\Exceptions\ValidationException;

/**
 * DGII certification: Alanube signs a certification XML with the certificate it holds for the company and
 * returns a link to the signed file — which is why the taxpayer never needs the certificate on their side.
 */
class SignAlanubeCertificationDocumentAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly string $xml,
        private readonly string $fileName,
    ) {
    }

    public function execute(): string
    {
        $client = new Client($this->app, $this->company);

        $response = $client->postFile(
            $client->scopedPath('sign-document'),
            'xml',
            $this->xml,
            $this->fileName
        );

        return (string) ($response['signedDocumentUrl'] ?? '')
            ?: throw new ValidationException('Alanube signed the document but returned no URL.');
    }
}
