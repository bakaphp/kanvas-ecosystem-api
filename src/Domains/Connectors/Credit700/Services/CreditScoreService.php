<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Exception;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\Credit700\Client;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplicant;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Connectors\Credit700\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Leads\Models\Lead;

class CreditScoreService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
        $this->client = new Client($app, $company);
    }

    public function getCreditScore(CreditApplicant $creditApplication, UserInterface $userRequestingReport, string $bureau = 'TU'): array
    {
        // $this->app->get(ConfigurationEnum::BUREAU_SETTING->value) ?? 'TU';
        $appOrCompany = $this->company ?? $this->app;

        try {
            $bureau = Str::replace('|', ':', $bureau);
            $bureauTypes = explode(':', $bureau);
            $data = [
                'ACCOUNT' => $appOrCompany->get(ConfigurationEnum::ACCOUNT->value),
                'PASSWD' => $appOrCompany->get(ConfigurationEnum::PASSWORD->value),
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
            $pullCreditPass = false;

            foreach ($bureauTypes as $bureauType) {
                // Check if risk_models exist in the response
                if (isset($responseArray['bureau_xml_data'][ucwords($bureauType) . '_Report'])) {
                    $scores[$bureauType] = $responseArray['bureau_xml_data'][ucwords($bureauType) . '_Report'];

                    // Check if ScoreRange is not empty to determine pass status
                    if (! empty($scores[$bureauType]['ScoreRange'])) {
                        $pullCreditPass = true;
                    }
                }
            }

            // Extract iframe URL
            $iframeUrl = $responseArray['custom_report_url']['iframe']['@attributes']['src'] ?? null;
            $pdfBase64 = $responseArray['pdf_report']['tu_pdf_report'] ?? null;

            try {
                $fileSystem = new FilesystemServices($this->app);
                $fileName = 'credit-pull-' . Str::replace(':', '-', $bureau) . '.pdf';
                $pdf = ! empty($pdfBase64) ? $fileSystem->createFileSystemFromBase64($pdfBase64, $fileName, $userRequestingReport) : null;
            } catch (Exception $e) {
                $pdf = null;
            }

            return [
                'scores' => $scores,
                'pull_credit_pass' => $pullCreditPass, // New field added
                'iframe_url' => $iframeUrl,
                'iframe_url_signed' => $iframeUrl !== null ? $this->generateSignedIframeUrl($iframeUrl, $userRequestingReport->firstname) : null,
                'iframe_url_digital_jacket' => $iframeUrl !== null ? $this->generateSignedIframeUrl($iframeUrl, $userRequestingReport->firstname) : null,
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

    public function regenerateLeadCreditHistoryUrl(Lead $lead): array
    {
        $leadPullCreditHistory = $lead->get(CustomFieldEnum::LEAD_PULL_CREDIT_HISTORY->value);

        if (empty($leadPullCreditHistory)) {
            return [];
        }

        foreach ($leadPullCreditHistory as $key => $history) {
            if (empty($history['iframe_url'])) {
                unset($leadPullCreditHistory[$key]);

                continue;
            }

            $leadPullCreditHistory[$key]['iframe_url_signed'] = $this->generateSignedIframeUrl($history['iframe_url'], $lead->user->firstname);
        }

        $leadPullCreditHistory = array_values($leadPullCreditHistory);
        $lead->set(
            CustomFieldEnum::LEAD_PULL_CREDIT_HISTORY->value,
            $leadPullCreditHistory
        );

        return $leadPullCreditHistory;
    }
}
