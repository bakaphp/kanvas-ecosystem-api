<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

class IdVerificationReportActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        try {
            // Extract verification data from params
            $verificationData = $params['params'] ?? [];

            $isShowRoom = $params['is_showroom'] ?? false;

            // Get person name from lead entity
            $name = $entity->title ?? $entity->people->name ?? 'Customer';

            // Process data to generate verification results
            $verificationResults = $this->processIntellicheckData($verificationData, $name, $isShowRoom);
            $company = $entity->company;

            // Generate report HTML using the template
            // $reportHtml = $this->generateIntellicheckReport(
            //     $verificationResults['message'],
            //     $verificationData,
            //     $verificationResults['status'],
            //     $verificationResults['results'],
            //     $verificationResults['failures'],
            //     $verificationResults['flags']
            // );

            // Prepare data to pass to the Blade template

            return $this->executeIntegration(
                entity: $entity,
                app: $app,
                integration: IntegrationsEnum::INTELLICHECK,
                integrationOperation: function ($entity, $app) use ($name, $verificationResults, $verificationData, $isShowRoom) {
                    $reportData = [
                        'name'                    => $name,
                        'status'                  => $verificationResults['status'],
                        'message'                 => $verificationResults['message'],
                        'flags'                   => $verificationResults['flags'],
                        'failures'                => $verificationResults['failures'],
                        'results'                 => $verificationResults['results'],
                        'verificationData'        => $verificationData,
                        'id_verification_status'  => $verificationResults['status'],
                        'id_verification_message' => $verificationResults['message'],
                        'id_verification_result'  => [
                            'intelicheck'          => $verificationResults['status'] == 'green' || $verificationResults['status'] == 'flag',
                            'status'               => $verificationResults['status'],
                            'message'              => $verificationResults['message'],
                            'scandit'              => $verificationResults['status'] == 'green' || $verificationResults['status'] == 'flag',
                            'expired'              => $verificationResults['status'] == 'flag',
                            'ocMatch'              => $verificationResults['ocMatch'] ?? false,
                            'intellicheckResponse' => $verificationResults['status'],
                        ],
                    ];

                    $usersToNotify = UsersRepository::findUsersByArray($entity->company->get('company_manager'), $app);
                    $notification = new Blank(
                        'id-verification-report',
                        [
                            'message'          => $reportData['message'],
                            'status'           => $reportData['status'],
                            'flags'            => $reportData['flags'],
                            'failures'         => $reportData['failures'],
                            'results'          => $reportData['results'],
                            'isShowRoom'       => $isShowRoom,
                            'verificationData' => $verificationData,
                        ],
                        ['mail'],
                        $entity,
                    );

                    $notification->setSubject('ID Verification Report');
                    Notification::send($usersToNotify, $notification);

                    return [
                        'report'  => $reportData['status'],
                        'result'  => true,
                        'message' => 'IdVerificationReportActivity executed successfully',
                        'data'    => $reportData,
                    ];
                },
                company: $company,
            );
        } catch (Throwable $e) {
            return [
                'report'  => 'fail',
                'result'  => false,
                'message' => 'Error processing ID verification: '.$e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ];
        }
    }

    /**
     * Process the Intellicheck data to determine verification status.
     */
    private function processIntellicheckData(array $verificationData, string $name, bool $isShowRoom = false): array
    {
        $flags = [];
        $failures = [];
        $results = [];
        $message = '';
        $flagNotice = false;
        $ocMatch = false;

        // Extract nested data safely with null coalescing
        $facial = $verificationData['idcheck']['data']['facial']['data'] ?? [];
        $ocrMatch = $verificationData['ocr_match']['data'] ?? [];
        $idCheck = $verificationData['idcheck']['data'] ?? [];
        $ipqsAddress = $verificationData['ipqs']['addressDetails']['data'] ?? [];
        $ipqsFraud = $verificationData['ipqs']['fraudDetails']['data'] ?? [];
        $ocrData = $verificationData['OCR']['data'] ?? [];

        // Track failure and flag groups
        $failureGroups = [];
        $flagGroups = [];

        // FACIAL CHECK
        if (! ($facial['matched'] ?? false) && $isShowRoom === false) {
            $failures[] = 'Facial data not matched';
            $failureGroups[] = 'facial check fail';
        }
        if (! ($facial['isLive'] ?? false) && $isShowRoom === false) {
            $failures[] = 'Facial data is not live';
            $failureGroups[] = 'facial check fail';
        }
        $results['facial_match_probability'] = $facial['matchProbability'] ?? null;
        $results['facial_liveness_probability'] = $facial['livenessProbability'] ?? null;

        // OCR CHECK
        $ocrRequiredMatches = array_filter([
            $ocrMatch['isDlClassMatch'] ?? false,
            $ocrMatch['isDobMatch'] ?? false,
            $ocrMatch['isHeightMatch'] ?? false,
            $ocrMatch['isAddressMatch'] ?? false,
            $ocrMatch['isIssueDateMatch'] ?? false,
            $ocrMatch['isDocumentNumberMatch'] ?? false,
            $ocrMatch['isIssuerNameMatch'] ?? false,
            $ocrMatch['isRealIdMatch'] ?? false,
            $ocrMatch['isSexMatch'] ?? false,
            $ocrMatch['isExpirationDateMatch'] ?? false,
            $ocrMatch['isNameMatch'] ?? false,
        ]);

        $totalOcrMatches = count($ocrRequiredMatches);
        $ocrMatchScore = $totalOcrMatches > 0 ? $totalOcrMatches / 11 * 100 : 0;
        $results['ocr_required_matches'] = $ocrMatchScore;
        $ocMatch = $ocrMatchScore >= 75;

        if ($ocrMatchScore < 50) {
            $failures[] = 'OCR match score below 50%';
            $failureGroups[] = 'OCR mismatch';
        } elseif ($ocrMatchScore < 75) {
            $flags[] = 'OCR match score below 75%';
            $flagGroups[] = 'OCR mismatch';
            $flagNotice = true;
        }

        // ID CHECK
        $isExpired = strtolower($idCheck['expired'] ?? 'no') === 'yes';
        if ($isExpired) {
            $flags[] = 'ID is expired';
            $flagGroups[] = 'ID check flag';
            $flagNotice = true;
        }

        if (strtolower($idCheck['processResult'] ?? '') === 'documentunknown') {
            $failures[] = 'ID process result is unknown';
            $failureGroups[] = 'ID check fail';
        } elseif (strtolower($idCheck['processResult'] ?? '') !== 'documentprocessok' && strtolower($idCheck['processResult'] ?? '') !== 'documentunknown') {
            $flags[] = 'ID process result is '.($idCheck['processResult'] ?? 'unknown');
            $flagGroups[] = 'ID check incomplete';
        }

        if (strtolower($idCheck['stateIssuerMismatch'] ?? '') === 'yes') {
            $flags[] = 'State issuer mismatch';
            $flagGroups[] = 'ID check incomplete';
        }

        // BEHAVIOR RISKS
        $riskScore = $ipqsAddress['transaction_details']['risk_score'] ?? 0;
        $results['risk_score'] = $riskScore;

        // CONNECTION RISKS
        $fraudScore = $ipqsAddress['fraud_score'] ?? 0;
        $results['fraud_score'] = $fraudScore;

        // IPQS Fraud Details
        $fraudChance = $ipqsFraud['fraud_chance'] ?? 0;
        $results['fraud_chance'] = $fraudChance;

        // Count scores above thresholds
        $scoresAbove90 = 0;
        $scoresAbove75 = 0;
        foreach ([$riskScore, $fraudScore, $fraudChance] as $score) {
            if ($score >= 90) {
                $scoresAbove90++;
            }
            if ($score >= 75) {
                $scoresAbove75++;
            }
        }

        // Add score-based failures/flags
        if ($scoresAbove90 >= 2) {
            $failures[] = 'Multiple risk scores >= 90';
            $failureGroups[] = 'behavior risk';
            if ($riskScore >= 90) {
                $failures[] = 'Risk score';
            }
            if ($fraudScore >= 90) {
                $failures[] = 'Fraud score';
            }
            if ($fraudChance >= 90) {
                $failures[] = 'Fraud chance';
            }
        } elseif ($scoresAbove75 >= 2) {
            $flags[] = 'Multiple risk scores >= 75';
            if ($riskScore >= 75) {
                $flags[] = 'Risk score';
            }
            if ($fraudScore >= 75) {
                $flags[] = 'Fraud score';
            }
            if ($fraudChance >= 75) {
                $flags[] = 'Fraud chance';
            }
            $flagGroups[] = 'behavior risk';
            $flagNotice = true;
        }

        if ($ipqsAddress['transaction_details']['fraudulent_behavior'] ?? false) {
            $flags[] = 'Fraudulent behavior detected';
            $flagGroups[] = 'behavior risk';
        }

        if ($ipqsAddress['transaction_details']['leaked_user_data'] ?? false) {
            $flags[] = 'Leaked user data detected';
            $flagGroups[] = 'behavior risk';
        }

        if (($ipqsAddress['transaction_details']['name_address_identity_match'] ?? '') === 'Mismatch' ||
            ($ipqsAddress['transaction_details']['name_address_identity_match'] ?? '') === 'No match') {
            $flags[] = 'Name and address identity mismatch';
            $flagGroups[] = 'behavior risk';
        }

        if (strtolower($ipqsAddress['city'] ?? '') !== strtolower($idCheck['city'] ?? '')) {
            $flags[] = 'City mismatch between IPQS and ID';
            $flagGroups[] = 'connection risk';
        }

        if (($ipqsAddress['country_code'] ?? 'US') !== 'US') {
            $flags[] = 'Country code mismatch';
            $flagGroups[] = 'connection risk';
        }

        if ($ipqsAddress['recent_abuse'] ?? false) {
            $flags[] = 'Recent abuse detected';
            $flagGroups[] = 'connection risk';
        }

        if ($ipqsAddress['frequent_abuser'] ?? false) {
            $flags[] = 'Frequent abuser detected';
            $flagGroups[] = 'connection risk';
        }

        if ($ipqsAddress['high_risk_attacks'] ?? false) {
            $flags[] = 'High risk attacks detected';
            $flagGroups[] = 'connection risk';
        }

        if ($ipqsAddress['vpn'] ?? false) {
            $flags[] = 'VPN detected';
            $flagGroups[] = 'connection risk';
        }

        if ($ipqsAddress['active_vpn'] ?? false) {
            $flags[] = 'Active VPN detected';
            $flagGroups[] = 'connection risk';
        }

        if (($ipqsAddress['abuse_velocity'] ?? '') === 'True') {
            $flags[] = 'High abuse velocity detected';
            $flagGroups[] = 'connection risk';
        }

        // Include risk factors in results
        $results['risk_factors'] = implode(', ', $ipqsAddress['transaction_details']['risk_factors'] ?? []);

        // Final Message Logic
        $failedGroups = array_unique($failureGroups);
        $flaggedGroups = array_unique($flagGroups);

        if (empty($failures)) {
            if (count($flags) >= 3 || $flagNotice) {
                // Create message using flag groups
                $flagReasons = [];
                foreach ($flaggedGroups as $group) {
                    switch ($group) {
                        case 'OCR mismatch':
                            $flagReasons[] = 'document verification concerns';

                            break;
                        case 'ID check incomplete':
                        case 'ID check flag':
                            $flagReasons[] = 'incomplete ID verification';

                            break;
                        case 'behavior risk':
                            $flagReasons[] = 'suspicious behavior patterns';

                            break;
                        case 'connection risk':
                            $flagReasons[] = 'connection security concerns';

                            break;
                        default:
                            $flagReasons[] = $group;
                    }
                }

                $message = "$name ID Verification needs further investigation due to ".implode(', ', $flagReasons).'. Proceed with caution.';
                $status = 'flag';
            } else {
                $message = "$name passed the ID Verification.";
                $status = 'green';
            }
        } else {
            if ($isExpired) {
                $message = "$name failed the ID Verification due to expired ID".(! empty($failedGroups) ? ' and detected fraud from '.implode(', ', $failedGroups) : '').'. Proceed with caution.';
            } else {
                $message = "$name failed the ID Verification due to detected fraud from ".implode(', ', $failedGroups).'. Proceed with caution.';
            }
            $status = 'fail';
        }

        return [
            'status'   => $status,
            'message'  => $message,
            'flags'    => $flags,
            'failures' => $failures,
            'results'  => $results,
            'ocMatch'  => $ocMatch,
        ];
    }
}
