<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Exception;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\Credit700\Client;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplicant;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Services\FilesystemServices;

class CreditScoreService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->client = new Client($app);
    }

    public function getCreditScore(CreditApplicant $creditApplication, UserInterface $userRequestingReport, string $bureau = 'TU'): array
    {
        // $this->app->get(ConfigurationEnum::BUREAU_SETTING->value) ?? 'TU';
        try {
            $data = [
                'ACCOUNT' => $this->app->get(ConfigurationEnum::ACCOUNT->value),
                'PASSWD' => $this->app->get(ConfigurationEnum::PASSWORD->value),
                'PRODUCT' => 'CREDIT',
                'BUREAU' => $bureau, // Can be XPN, TU, or EFX
                'PASS' => '2',
                'PROCESS' => 'PCCREDIT',
                'NAME' => $creditApplication->name,
                'ADDRESS' => $creditApplication->address,
                'CITY' => $creditApplication->city,
                'STATE' => $creditApplication->state,
                'ZIP' => $creditApplication->zip,
                'SSN' => $creditApplication->ssn,
            ];

            if (Str::contains($bureau, ':')) {
                $data['MULTIBUR'] = $data['BUREAU'];
                unset($data['BUREAU']);
            }

            $responseArray = $this->client->post(
                '/Request',
                $data
            );

            $scores = [];

            $bureauType = $this->app->get(ConfigurationEnum::BUREAU_SETTING->value) ?? 'TU';
            // Check if risk_models exist in the response
            if (isset($responseArray['bureau_xml_data'][ucwords($bureauType) . '_Report']['subject_segments']['scoring_segments']['scoring'])) {
                $scores = $responseArray['bureau_xml_data'][ucwords($bureauType) . '_Report']['subject_segments']['scoring_segments']['scoring'];
            }

            // Extract iframe URL
            $iframeUrl = $responseArray['custom_report_url']['iframe']['@attributes']['src'] ?? null;
            $pdfBase64 = $responseArray['pdf_report']['tu_pdf_report'] ?? null;

            try {
                $fileSystem = new FilesystemServices($this->app);
                $pdf = ! empty($pdfBase64) ? $fileSystem->createFileSystemFromBase64($pdfBase64, 'credit-app.pdf', $userRequestingReport) : null;
            } catch (Exception $e) {
                $pdf = null;
            }

            return [
                'scores' => $scores,
                'iframe_url' => $iframeUrl,
                'iframe_url_signed' => $iframeUrl !== null ? $this->generateSignedIframeUrl($iframeUrl, $userRequestingReport->firstname) : null,
                'pdf' => $pdf,
            ];
        } catch (RequestException $e) {
            throw new ValidationException('Failed to retrieve credit score: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new ValidationException('An error occurred: ' . $e->getMessage());
        }
    }

    /**
     * Generate and sign the iFrame URL for accessing the credit report.
     */
    public function generateSignedIframeUrl(string $unsignedUrl, string $signedBy): string
    {
        try {
            // Sign the URL
            return $this->client->signUrl($unsignedUrl, $signedBy); // 30-minute expiration
        } catch (Exception $e) {
            throw new ValidationException('Failed to generate signed URL: ' . $e->getMessage());
        }
    }
}
