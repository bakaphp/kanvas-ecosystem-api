<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Services;

class IdVerificationService
{
    public static function getName(array $verificationData): string
    {
        // Try to get name from idcheck data first
        $idCheckData = $verificationData['idcheck']['data'] ?? [];

        if (! empty($idCheckData['firstName']) || ! empty($idCheckData['lastName'])) {
            $firstName = $idCheckData['firstName'] ?? '';
            $middleName = ! empty($idCheckData['middleName']) ? " {$idCheckData['middleName']}" : '';
            $lastName = $idCheckData['lastName'] ?? '';

            return trim("$firstName$middleName $lastName");
        }

        // Fallback to OCR data if idcheck name is not available
        $ocrData = $verificationData['OCR']['data'] ?? [];

        if (! empty($ocrData['fullName'])) {
            return $ocrData['fullName'];
        } elseif (! empty($ocrData['firstName']) || ! empty($ocrData['lastName'])) {
            $firstName = $ocrData['firstName'] ?? '';
            $lastName = $ocrData['lastName'] ?? '';

            return trim("$firstName $lastName");
        }

        // Return Unknown if no name found in either source
        return 'Unknown';
    }

    public static function processVerificationData(
        array $verificationData,
        string $name,
        bool $isShowRoom = false
    ): array {
        $flags = [];
        $failures = [];
        $results = [];
        $message = '';
        $flagNotice = false;
        $ocMatch = false;

        // Extract nested data safely with null coalescing
        $facial = $verificationData['facial']['data'] ?? [];
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

        // OCR CHECK - ADJUSTED FOR SHOWROOM MODE
        $hasOcrFailure = false;
        $ocrMatchFields = [
            'isDlClassMatch',
            'isDobMatch',
            'isHeightMatch',
            'isAddressMatch',
            'isIssueDateMatch',
            'isDocumentNumberMatch',
            'isIssuerNameMatch',
            'isRealIdMatch',
            'isSexMatch',
            'isExpirationDateMatch',
            'isNameMatch',
        ];

        // Define critical fields for showroom mode
        $criticalFields = [
            'isNameMatch',
            'isDocumentNumberMatch',
            'isDobMatch',
            'isSexMatch',
        ];

        $ocrFailedFields = [];
        $criticalFailedFields = [];
        $nonCriticalFailedFields = [];
        $allFieldsFailed = true;

        foreach ($ocrMatchFields as $field) {
            if (isset($ocrMatch[$field])) {
                if ($ocrMatch[$field] === false) {
                    $ocrFailedFields[] = $field;

                    // Separate critical vs non-critical fields for showroom mode
                    if ($isShowRoom && in_array($field, $criticalFields)) {
                        $criticalFailedFields[] = $field;
                    } else {
                        $nonCriticalFailedFields[] = $field;
                    }
                } else {
                    $allFieldsFailed = false;
                }
            } else {
                $allFieldsFailed = false;
            }
        }

        // Handle OCR failures based on mode
        if ($isShowRoom) {
            // In showroom mode: critical field mismatches are failures, others are flags
            if (! empty($criticalFailedFields)) {
                $criticalReadableFields = array_map(function ($field) {
                    $readable = str_replace(['is', 'Match'], '', $field);

                    return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $readable));
                }, $criticalFailedFields);

                $failures[] = 'Critical OCR verification failed: ' . implode(', ', $criticalReadableFields);
                $failureGroups[] = 'OCR critical mismatch';
                $hasOcrFailure = true;
            }

            if (! empty($nonCriticalFailedFields)) {
                $nonCriticalReadableFields = array_map(function ($field) {
                    $readable = str_replace(['is', 'Match'], '', $field);

                    return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $readable));
                }, $nonCriticalFailedFields);

                $flags[] = 'OCR verification issues: ' . implode(', ', $nonCriticalReadableFields);
                $flagGroups[] = 'OCR non-critical mismatch';
                $flagNotice = true;
            }
        } else {
            // Original logic for non-showroom mode
            if ($allFieldsFailed && ! empty($ocrFailedFields)) {
                $failures[] = 'OCR verification failed: ' . implode(', ', $ocrFailedFields);
                $failureGroups[] = 'OCR mismatch';
            }

            if (! empty($ocrFailedFields)) {
                $readableFailedFields = array_map(function ($field) {
                    $readable = str_replace(['is', 'Match'], '', $field);

                    return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $readable));
                }, $ocrFailedFields);

                $flags[] = 'OCR verification issues: ' . implode(', ', $readableFailedFields);
                $flagGroups[] = 'OCR mismatch';
                $flagNotice = true;
            }
        }

        // Count total matches for reporting purposes
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
        $ocMatch = ! $hasOcrFailure;

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
        } elseif (
            in_array(strtolower($idCheck['processResult'] ?? ''), ['documentbadread', 'documentbaddevice'])
        ) {
            $flags[] = 'ID process result is ' . ($idCheck['processResult'] ?? 'unknown');
            $flagGroups[] = 'ID check incomplete';
            $flagNotice = true;
        } elseif (
            strtolower($idCheck['processResult'] ?? '') !== 'documentprocessok' &&
            strtolower($idCheck['processResult'] ?? '') !== 'documentunknown'
        ) {
            $flags[] = 'ID process result is ' . ($idCheck['processResult'] ?? 'unknown');
            $flagGroups[] = 'ID check incomplete';
            $flagNotice = true;
        }

        if (strtolower($idCheck['stateIssuerMismatch'] ?? '') === 'yes') {
            $flags[] = 'State issuer mismatch';
            $flagGroups[] = 'ID check incomplete';
        }

        // Skip IPQS validation if in showroom mode or IPQS address data is empty
        $skipIpqsValidation = $isShowRoom || empty($ipqsAddress);
        $flagGroupScores = [];

        if (! $skipIpqsValidation) {
            // BEHAVIOR RISKS - NEW RULE (remove failure conditions, only keep flag)
            $riskScore = $ipqsAddress['transaction_details']['risk_score'] ?? 0;
            $results['risk_score'] = $riskScore;

            // CONNECTION RISKS
            $fraudScore = $ipqsAddress['fraud_score'] ?? 0;
            $results['fraud_score'] = $fraudScore;

            // IPQS Fraud Details
            $fraudChance = $ipqsFraud['fraud_chance'] ?? 0;
            $results['fraud_chance'] = $fraudChance;

            // Count scores above thresholds - only consider flagging now
            $scoresAbove75 = 0;
            foreach ([$riskScore, $fraudScore, $fraudChance] as $score) {
                if ($score >= 75) {
                    $scoresAbove75++;
                }
            }

            // Add score-based flags (no failures for risk scores now)
            $flagGroupScores = [];
            if ($scoresAbove75 >= 1) {
                $flags[] = 'Multiple risk scores >= 75';
                if ($riskScore >= 75) {
                    $flags[] = 'Risk score';
                    $flagGroupScores[] = 'Risk score';
                }
                if ($fraudScore >= 75) {
                    $flags[] = 'Fraud score';
                    $flagGroupScores[] = 'Fraud score';
                }
                if ($fraudChance >= 75) {
                    $flags[] = 'Fraud chance';
                    $flagGroupScores[] = 'Fraud chance';
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
        } else {
            // In showroom mode or empty IPQS data, add these values to results but set them to 0
            $results['risk_score'] = 0;
            $results['fraud_score'] = 0;
            $results['fraud_chance'] = 0;
            $results['risk_factors'] = '';
        }

        // Include risk factors in results
        $results['risk_factors'] = implode(', ', $ipqsAddress['transaction_details']['risk_factors'] ?? []);

        // Final Message Logic
        $failedGroups = array_unique($failureGroups);
        $flaggedGroups = array_unique($flagGroups);

        if (empty($failures)) {
            // Always make sure expired IDs are flagged
            if ($isExpired || ($flagNotice && count($flagGroupScores) >= 2)) {
                // Create message using flag groups
                $flagReasons = [];
                foreach ($flaggedGroups as $group) {
                    switch ($group) {
                        case 'OCR mismatch':
                        case 'OCR non-critical mismatch':
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

                // If expired ID is the only issue, make sure we mention it explicitly
                if ($isExpired && empty($flagReasons)) {
                    $message = "$name ID Verification needs further investigation due to expired ID. Proceed with caution.";
                } else {
                    $message = "$name ID Verification needs further investigation due to " . implode(', ', $flagReasons) .
                        ($isExpired ? ' and expired ID' : '') . '. Proceed with caution.';
                }
                $status = 'flag';
            } else {
                $message = "$name passed the ID Verification.";
                $status = 'green';
            }
        } else {
            $message = "$name failed the ID Verification due to detected fraud from " .
                implode(', ', $failedGroups) . '. Proceed with caution.';
            $status = 'fail';
        }

        return [
            'status' => $status,
            'message' => $message,
            'flags' => $flags,
            'failures' => $failures,
            'results' => $results,
            'ocMatch' => $ocMatch,
        ];
    }
}
