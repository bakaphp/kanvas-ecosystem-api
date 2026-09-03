<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intellicheck\Services;

use Carbon\Carbon;
use Throwable;

class IdVerificationService
{
    private const int OCR_MISMATCH_FAILURE_THRESHOLD = 4;

    /**
     * Each match flag paired with the two sides it compares: [idcheck keys, OCR key].
     * A side that arrives blank means the field was never really compared.
     */
    private const array OCR_FIELD_SOURCES = [
        'isNameMatch' => [['firstName', 'lastName'], 'fullName'],
        'isDocumentNumberMatch' => [['dLIDNumberRaw'], 'documentNumber'],
        'isDobMatch' => [['dateOfBirth'], 'dateOfBirthFormatted'],
        'isSexMatch' => [['gender'], 'sex'],
        'isAddressMatch' => [['address1'], 'address'],
        'isExpirationDateMatch' => [['expirationDate'], 'dateOfExpiryFormatted'],
        'isIssuerNameMatch' => [['issuingJurisdictionCvt'], 'issuerName'],
        'isIssueDateMatch' => [['issueDate'], 'dateOfIssueFormatted'],
        'isRealIdMatch' => [['isRealID'], 'isRealID'],
        'isHeightMatch' => [['heightCentimeters'], 'height'],
        'isDlClassMatch' => [['driverClass'], 'dlClass'],
    ];

    /**
     * Only these can mark the comparison incomplete. The rest are optional per issuing
     * jurisdiction — a state that doesn't print Real ID or height omits them from the
     * payload, and treating that as incomplete would make green unreachable there.
     */
    private const array OCR_REQUIRED_FIELDS = [
        'isNameMatch',
        'isDocumentNumberMatch',
        'isDobMatch',
        'isSexMatch',
        'isAddressMatch',
        'isExpirationDateMatch',
    ];

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

    /** Reshapes an Intellicheck response into the `get_docs_drivers_license` scan payload. */
    public static function toDriverLicenseScan(array $verificationData): ?array
    {
        $idCheck = $verificationData['idcheck']['data'] ?? null;

        if (! is_array($idCheck)) {
            return null;
        }

        $dateOfBirth = isset($idCheck['dateOfBirth']) ? strtotime((string) $idCheck['dateOfBirth']) : false;
        $expirationDate = isset($idCheck['expirationDate']) && is_numeric($idCheck['expirationDate'])
            ? strtotime((string) $idCheck['expirationDate'])
            : false;

        return [
            'address' => $verificationData['ocr_match']['data']['address'] ?? '',
            'state' => $idCheck['state'] ?? '',
            'birthday' => self::toDateParts($dateOfBirth),
            'license' => $idCheck['dLIDNumberRaw'] ?? '',
            'exp_date' => self::toDateParts($expirationDate),
            'state_id' => 0,
            'firstname' => $idCheck['firstName'] ?? '',
            'middlename' => '',
            'lastname' => $idCheck['lastName'] ?? '',
        ];
    }

    /**
     * @return array{day: int, month: int, year: int}
     */
    private static function toDateParts(int|false $timestamp): array
    {
        if ($timestamp === false) {
            return ['day' => 0, 'month' => 0, 'year' => 0];
        }

        return [
            'day' => (int) date('d', $timestamp),
            'month' => (int) date('m', $timestamp),
            'year' => (int) date('Y', $timestamp),
        ];
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
        $ocrNotice = false;
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

        $ocrMismatchedFields = [];
        $ocrIncompleteFields = [];
        $ocrComparedCount = 0;

        foreach (array_keys(self::OCR_FIELD_SOURCES) as $field) {
            if (! isset($ocrMatch[$field]) || self::hasBlankComparisonSide($field, $idCheck, $ocrData)) {
                if (in_array($field, self::OCR_REQUIRED_FIELDS, true)) {
                    $ocrIncompleteFields[] = $field;
                }

                continue;
            }

            $ocrComparedCount++;

            if ($ocrMatch[$field] === false) {
                $ocrMismatchedFields[] = $field;
            }
        }

        $ocrMismatchCount = count($ocrMismatchedFields);
        $hasOcrFailure = $ocrMismatchCount >= self::OCR_MISMATCH_FAILURE_THRESHOLD;

        if ($hasOcrFailure) {
            $failures[] = 'Computer Vision verification failed: ' . self::readableOcrFields($ocrMismatchedFields);
            $failureGroups[] = 'OCR mismatch';
        } elseif ($ocrMismatchCount > 0) {
            $flags[] = 'Computer Vision mismatches: ' . self::readableOcrFields($ocrMismatchedFields);
            $flagGroups[] = 'OCR mismatch';
            $flagNotice = true;
            $ocrNotice = true;
        }

        if (! empty($ocrIncompleteFields)) {
            $flags[] = 'Computer Vision could not compare: ' . self::readableOcrFields($ocrIncompleteFields);
            $flagGroups[] = 'OCR incomplete';
            $flagNotice = true;
            $ocrNotice = true;
        }

        $results['ocr_required_matches'] =
            ($ocrComparedCount - $ocrMismatchCount) / count(self::OCR_FIELD_SOURCES) * 100;
        $ocMatch = ! $hasOcrFailure;

        // ID CHECK
        // Expiry can come from the idcheck block OR the OCR block. A blurry/bad
        // back image leaves the idcheck block empty (DocumentBadDevice), so
        // without the OCR fallback an expired license slips through as valid.
        $ocrExpired = false;
        $ocrExpiry = (string) ($ocrData['dateOfExpiry'] ?? '');
        if ($ocrExpiry !== '') {
            try {
                $ocrExpired = Carbon::parse($ocrExpiry)->isPast();
            } catch (Throwable) {
                $ocrExpired = false;
            }
        }

        $isExpired = strtolower($idCheck['expired'] ?? 'no') === 'yes' || $ocrExpired;
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

        // A failed idcheck (blurry image, bad device, etc.) means the document
        // could not actually be verified. It must never resolve to green/passed —
        // force at least a flag so it surfaces for manual review.
        $idCheckUnverifiable = ($verificationData['idcheck']['success'] ?? false) === false;
        if ($idCheckUnverifiable) {
            $flagNotice = true;
            if (! in_array('ID check incomplete', $flagGroups, true)) {
                $flags[] = 'ID could not be verified';
                $flagGroups[] = 'ID check incomplete';
            }
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
            if ($isExpired || $idCheckUnverifiable || $ocrNotice || ($flagNotice && count($flagGroupScores) >= 2)) {
                // Create message using flag groups
                $flagReasons = [];
                foreach ($flaggedGroups as $group) {
                    switch ($group) {
                        case 'OCR mismatch':
                            $flagReasons[] = 'document verification concerns';

                            break;
                        case 'OCR incomplete':
                            $flagReasons[] = 'incomplete document comparison';

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

    private static function readableOcrFields(array $fields): string
    {
        return implode(', ', array_map(
            function (string $field): string {
                $readable = str_replace(['is', 'Match'], '', $field);

                return trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $readable));
            },
            $fields
        ));
    }

    private static function hasBlankComparisonSide(string $field, array $idCheck, array $ocrData): bool
    {
        [$idCheckKeys, $ocrKey] = self::OCR_FIELD_SOURCES[$field];

        foreach ($idCheckKeys as $idCheckKey) {
            if (self::isBlankValue($idCheck[$idCheckKey] ?? null)) {
                return true;
            }
        }

        return self::isBlankValue($ocrData[$ocrKey] ?? null);
    }

    /**
     * A boolean false is a real answer, not a blank — `empty()` would wrongly
     * discard a legitimate "Real ID: no".
     */
    private static function isBlankValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }
}
