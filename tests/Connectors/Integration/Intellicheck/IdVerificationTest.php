<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Services\IdVerificationService;
use Kanvas\Connectors\Intellicheck\Services\PeopleService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class IdVerificationTest extends TestCase
{
    protected function idVerificationData(): array
    {
        return [
            'is_showroom' => true,
            'idcheck' => [
                'data' => [
                    'uniqueID' => 2119,
                    'weightPounds' => null,
                    'heightFeetInches' => "5' 7\"",
                    'age' => 40,
                    'driverClass' => 'E',
                    'restrictions' => 'A',
                    'address1' => '10405 Sw 112th St',
                    'dLIDNumberRaw' => 'K523503856060',
                    'endorsements' => null,
                    'address2' => null,
                    'organDonor' => 'Yes',
                    'socialSecurity' => null,
                    'city' => 'Miami',
                    'issueDate' => '03/22/2006',
                    'transactionIdentifier' => 'ad1014e4-50f3-49db-a35c-57a884311814',
                    'processResult' => 'DocumentUnknown',
                    'gender' => 'Female',
                    'isDuplicate' => 'No',
                    'firstName' => 'Keira',
                    'issuingJurisdictionAbbrv' => 'FL',
                    'middleName' => 'Christina',
                    'eyeColor' => null,
                    'expired' => 'Yes',
                    'docType' => null,
                    'hairColor' => null,
                    'expirationDate' => '03/26/2012',
                    'extendedResultCode' => 'U',
                    'docCategory' => 'DL',
                    'stateIssuerMismatch' => 'No',
                    'heightCentimeters' => '170',
                    'duplicateDate' => null,
                    'lastName' => 'Knightley',
                    'issuingJurisdictionCvt' => 'Florida',
                    'dLIDNumberFormatted' => 'K523-503-85-606-0',
                    'postalCode' => '33176-3425',
                    'state' => 'FL',
                    'dateOfBirth' => '03/26/1985',
                    'isRealID' => null,
                    'mediaType' => '2D',
                    'testCard' => false,
                    'weightKilograms' => null,
                ],
                'result' => true,
                'success' => true,
                'message' => null,
            ],
            'OCR' => [
                'result' => true,
                'data' => [
                    'documentNumber' => 'W730421894796',
                    'eyeColor' => null,
                    'weightKilograms' => null,
                    'firstName' => 'WARNER ALVIN',
                    'dateOfBirth' => '1988-06-23',
                    'dlEndorsement' => null,
                    'faceImageBase64' => null,
                    'dateOfBirthFormatted' => '06/23/1988',
                    'errorMessage' => null,
                    'dateOfExpiryFormatted' => '12/29/2018',
                    'countryCode' => 'USA',
                    'dateOfExpiry' => '2018-12-29',
                    'dateOfIssue' => '2011-06-25',
                    'dlClass' => null,
                    'fullDocumentImageBase64' => null,
                    'dlRestrictions' => null,
                    'placeOfBirth' => null,
                    'age' => null,
                    'dateOfIssueFormatted' => '06/25/2011',
                    'address' => '2537 WAYMAN RD MOORE HAVEN, FL 33471-0000',
                    'fullName' => 'WAH WARNER ALVIN',
                    'isRealID' => 'yes',
                    'sex' => 'X',
                    'documentRecognized' => 1,
                    'lastName' => 'WAH',
                    'nationality' => null,
                    'issuerName' => 'Florida',
                    'height' => '180',
                ],
                'message' => null,
                'success' => true,
            ],
            'ocr_match' => [
                'data' => [
                    'addressMatchDetails' => [
                        'similarityThreshold' => 70,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                        'similarityScore' => 27,
                    ],
                    'isDocumentNumberMatch' => false,
                    'sexMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 0,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                    ],
                    'isDobMatch' => true,
                    'documentNumberMatchDetails' => [
                        'similarityScore' => 7,
                        'similarityThreshold' => 70,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                    ],
                    'isCountryCodeMatch' => null,
                    'dlClassMatchDetails' => [
                        'similarityScore' => null,
                        'details' => 'Missing value for comparison',
                        'similarityThreshold' => 70,
                    ],
                    'dobMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 70,
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                    ],
                    'issuerNameMatchDetails' => [
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 100,
                    ],
                    'isIssuerNameMatch' => true,
                    'dlRestrictionsMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => null,
                        'details' => 'Missing value for comparison',
                    ],
                    'weightMatchDetails' => [
                        'details' => 'Missing value for comparison',
                        'similarityScore' => null,
                        'similarityThreshold' => 70,
                    ],
                    'issueDateMatchDetails' => [
                        'similarityScore' => 60,
                        'similarityThreshold' => 70,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                    ],
                    'isWeightMatch' => null,
                    'isIssueDateMatch' => false,
                    'realIdMatchDetails' => [
                        'similarityScore' => null,
                        'details' => 'Missing value for comparison',
                        'similarityThreshold' => 70,
                    ],
                    'eyeColorMatchDetails' => [
                        'details' => 'Missing value for comparison',
                        'similarityThreshold' => 70,
                        'similarityScore' => null,
                    ],
                    'dlEndorsementMatchDetails' => [
                        'similarityThreshold' => 70,
                        'details' => 'Missing value for comparison',
                        'similarityScore' => null,
                    ],
                    'isDlRestrictionsMatch' => null,
                    'isSexMatch' => false,
                    'isNationalityMatch' => null,
                    'isDlEndorsementMatch' => null,
                    'isRealIdMatch' => null,
                    'expirationDateMatchDetails' => [
                        'similarityScore' => 60,
                        'similarityThreshold' => 70,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                    ],
                    'nameMatchDetails' => [
                        'similarityThreshold' => 70,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                        'similarityScore' => 21,
                    ],
                    'isNameMatch' => false,
                    'heightMatchDetails' => [
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityScore' => 75,
                        'similarityThreshold' => 70,
                    ],
                    'isExpirationDateMatch' => false,
                    'isHeightMatch' => true,
                    'isAddressMatch' => false,
                    'isDlClassMatch' => null,
                    'isEyeColorMatch' => null,
                ],
                'success' => false,
                'message' => null,
                'result' => true,
            ],
            'ip' => '172.18.0.24',
        ];
    }

    public function idVerificationFlag(): array
    {
        return [
            'idcheck' => [
                'data' => [
                    'dLIDNumberRaw' => '000000000',
                    'heightFeetInches' => "5' 10\"",
                    'extendedResultCode' => 'Y',
                    'driverClass' => 'DM',
                    'endorsements' => null,
                    'expirationDate' => '11/11/2032',
                    'docCategory' => 'DL',
                    'city' => 'Knoxville',
                    'postalCode' => '37932-2288',
                    'issueDate' => '11/11/2024',
                    'duplicateDate' => null,
                    'mediaType' => '2D',
                    'uniqueID' => 6211,
                    'expired' => 'No',
                    'docType' => null,
                    'dateOfBirth' => '1981-11-02',
                    'lastName' => 'Testington',
                    'middleName' => 'Alan',
                    'weightKilograms' => null,
                    'restrictions' => null,
                    'gender' => 'Male',
                    'weightPounds' => null,
                    'isDuplicate' => 'No',
                    'organDonor' => 'Yes',
                    'age' => 43,
                    'socialSecurity' => null,
                    'state' => 'TN',
                    'processResult' => 'DocumentProcessOK',
                    'stateIssuerMismatch' => 'No',
                    'issuingJurisdictionAbbrv' => 'TN',
                    'hairColor' => null,
                    'dLIDNumberFormatted' => '000000000',
                    'transactionIdentifier' => '2cbb2fb1-b57f-4b8a-974c-09957866acd5',
                    'issuingJurisdictionCvt' => 'Tennessee',
                    'address2' => null,
                    'firstName' => 'John',
                    'testCard' => false,
                    'eyeColor' => 'Blue',
                    'isRealID' => 'Yes',
                    'heightCentimeters' => '178',
                    'address1' => '1144 Belle Pond Ave',
                ],
                'message' => null,
                'result' => true,
                'success' => true,
            ],
            'facial' => [
                'data' => [
                    'matchProbability' => 1,
                    'errorMessage' => null,
                    'livenessProbability' => 0.99,
                    'isLive' => true,
                    'matched' => true,
                    'livenessScore' => 4.36,
                    'matchScore' => 92,
                ],
                'success' => true,
                'message' => null,
                'result' => true,
            ],
            'ocr_match' => [
                'data' => [
                    'realIdMatchDetails' => [
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                        'similarityScore' => 100,
                    ],
                    'issueDateMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'dobMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'expirationDateMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'heightMatchDetails' => [
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                    ],
                    'dlRestrictionsMatchDetails' => [
                        'similarityScore' => 99,
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityThreshold' => 70,
                    ],
                    'dlClassMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                    ],
                    'isDlClassMatch' => true,
                    'isDobMatch' => true,
                    'isDocumentNumberMatch' => true,
                    'nameMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'isHeightMatch' => true,
                    'isExpirationDateMatch' => true,
                    'issuerNameMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'weightMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => null,
                        'details' => 'Missing value for comparison',
                    ],
                    'dlEndorsementMatchDetails' => [
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityScore' => 99,
                        'similarityThreshold' => 70,
                    ],
                    'isDlRestrictionsMatch' => true,
                    'isWeightMatch' => null,
                    'documentNumberMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'sexMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'isEyeColorMatch' => true,
                    'isIssuerNameMatch' => true,
                    'isRealIdMatch' => true,
                    'isSexMatch' => true,
                    'isCountryCodeMatch' => null,
                    'isAddressMatch' => true,
                    'isIssueDateMatch' => true,
                    'isNationalityMatch' => null,
                    'eyeColorMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                    ],
                    'addressMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                    ],
                    'isDlEndorsementMatch' => true,
                    'isNameMatch' => true,
                ],
                'success' => true,
                'message' => null,
                'result' => true,
            ],
            'ipqs' => [
                'addressDetails' => [
                    'data' => [
                        'transaction_details' => [
                            'leaked_shipping_email' => null,
                            'valid_billing_address' => true,
                            'phone_name_identity_match' => 'Unknown',
                            'is_prepaid_card' => null,
                            'shipping_phone_carrier' => null,
                            'fraudulent_behavior' => false,
                            'billing_address_distance' => [
                                'kilometers' => 540,
                                'miles' => 336,
                            ],
                            'shipping_phone_country' => null,
                            'email_name_identity_match' => 'Unknown',
                            'phone_email_identity_match' => 'Unknown',
                            'shipping_phone_country_code' => null,
                            'phone_address_identity_match' => 'Unknown',
                            'valid_shipping_phone' => null,
                            'risky_username' => null,
                            'bin_country' => null,
                            'address_email_identity_match' => 'Unknown',
                            'shipping_phone_line_type' => 'Unknown',
                            'billing_phone_country' => null,
                            'billing_phone_country_code' => null,
                            'risky_shipping_phone' => null,
                            'billing_phone_line_type' => 'Unknown',
                            'name_address_identity_match' => 'Match',
                            'valid_billing_email' => null,
                            'leaked_user_data' => null,
                            'valid_shipping_address' => null,
                            'risk_factors' => [
                                'IP address is frequently associated with abusive behavior.',
                                'IP address recently engaged in suspicious behavior.',
                            ],
                            'user_activity' => null,
                            'bin_type' => 'N/A',
                            'shipping_address_distance' => [
                                'miles' => 336,
                                'kilometers' => 540,
                            ],
                            'valid_shipping_email' => null,
                            'billing_phone_carrier' => null,
                            'risk_score' => 89,
                            'bin_bank_name' => null,
                            'leaked_billing_email' => null,
                            'valid_billing_phone' => null,
                            'risky_billing_phone' => null,
                        ],
                        'bot_status' => false,
                        'frequent_abuser' => false,
                        'mobile' => false,
                        'active_tor' => false,
                        'success' => true,
                        'security_scanner' => false,
                        'recent_abuse' => false,
                        'ISP' => 'Cisco OpenDNS',
                        'longitude' => -90.08999634,
                        'active_vpn' => true,
                        'timezone' => 'America/Chicago',
                        'is_crawler' => false,
                        'country_code' => 'US',
                        'region' => 'Tennessee',
                        'high_risk_attacks' => false,
                        'dynamic_connection' => false,
                        'message' => 'Success',
                        'fraud_score' => 0,
                        'latitude' => 35.18999863,
                        'city' => 'Memphis',
                        'proxy_networks' => [
                            'Enterprise plan required to view active proxy networks.',
                        ],
                        'tor' => false,
                        'organization' => 'Cisco Secure Access',
                        'zip_code' => 'N/A',
                        'vpn' => true,
                        'host' => '155.190.17.5',
                        'shared_connection' => true,
                        'request_id' => 'Xwk8skZLDI',
                        'trusted_network' => true,
                        'connection_type' => 'Corporate',
                        'ASN' => 36692,
                        'abuse_velocity' => 'low',
                        'proxy' => true,
                    ],
                    'message' => null,
                    'result' => true,
                    'success' => true,
                ],
                'fraudDetails' => [
                    'data' => [
                        'first_seen' => '2025-06-10 14:55:10',
                        'longitude' => -90.09,
                        'organization' => 'Cisco Secure Access',
                        'tor' => false,
                        'is_crawler' => false,
                        'timezone' => 'America/Chicago',
                        'mobile' => true,
                        'bot_status' => false,
                        'connection_type' => 'Data Center',
                        'recent_abuse' => false,
                        'ip_address' => '155.190.17.5',
                        'browser' => 'Mobile Safari 18.5',
                        'proxy' => true,
                        'region' => 'Tennessee',
                        'active_tor' => false,
                        'device_id' => 'af2bfff29fef3b2ca8e2f18adadd5f5367294a6de5f6699b051cbe8318949964',
                        'isp' => 'Cisco OpenDNS',
                        'model' => 'iPhone',
                        'brand' => 'Apple',
                        'city' => 'Memphis',
                        'operating_system' => 'iOS 18.5',
                        'last_seen' => '2025-06-10 14:55:10',
                        'active_vpn' => true,
                        'fraud_chance' => 43,
                        'vpn' => true,
                        'latitude' => 35.19,
                        'request_id' => 'Xwk1YIDjbD',
                        'country' => 'US',
                        'reasons' => [
                            'VPN connection or anonymizer detected',
                            'User has an abnormal connection',
                            'Abnormal browser settings detected',
                        ],
                        'unique' => true,
                    ],
                    'success' => true,
                    'message' => null,
                    'result' => true,
                ],
            ],
            'OCR' => [
                'data' => [
                    'firstName' => 'John',
                    'issuerName' => 'Tennessee',
                    'countryCode' => 'USA',
                    'eyeColor' => 'Blue',
                    'dateOfIssueFormatted' => '11/11/2024',
                    'documentRecognized' => 1,
                    'dateOfBirthFormatted' => '11/02/1981',
                    'dateOfExpiry' => '2032-11-11',
                    'documentNumber' => '000000000',
                    'dlEndorsement' => 'NONE',
                    'dlRestrictions' => 'NONE',
                    'faceImageBase64' => null,
                    'fullName' => 'John Alan Testington',
                    'dateOfExpiryFormatted' => '11/11/2032',
                    'height' => '178 cm',
                    'dlClass' => 'DM',
                    'fullDocumentImageBase64' => null,
                    'address' => '1144 Belle Pond Ave, Knoxville, TN 37932-2288',
                    'age' => '43',
                    'lastName' => 'Testington',
                    'dateOfIssue' => '2024-11-11',
                    'weightKilograms' => null,
                    'dateOfBirth' => '1981-11-02',
                    'sex' => 'M',
                    'isRealID' => 'yes',
                    'errorMessage' => null,
                    'placeOfBirth' => null,
                    'nationality' => null,
                ],
                'message' => null,
                'success' => true,
                'imageQualityFindings' => [],
                'result' => true,
            ],
            'ip' => '172.19.0.34',
            'is_showroom' => true,
        ];
    }

    public function idVerificationFlagTestA(): array
    {
        return [
            'idcheck' => [
                'data' => [
                    'dLIDNumberRaw' => '000000000',
                    'heightFeetInches' => "5' 10\"",
                    'extendedResultCode' => 'Y',
                    'driverClass' => 'DM',
                    'endorsements' => null,
                    'expirationDate' => '11/11/2032',
                    'docCategory' => 'DL',
                    'city' => 'Knoxville',
                    'postalCode' => '37932-2288',
                    'issueDate' => '11/11/2024',
                    'duplicateDate' => null,
                    'mediaType' => '2D',
                    'uniqueID' => 6211,
                    'expired' => 'No',
                    'docType' => null,
                    'dateOfBirth' => '1981-11-02',
                    'lastName' => 'Testington',
                    'middleName' => 'Alan',
                    'weightKilograms' => null,
                    'restrictions' => null,
                    'gender' => 'Male',
                    'weightPounds' => null,
                    'isDuplicate' => 'No',
                    'organDonor' => 'Yes',
                    'age' => 43,
                    'socialSecurity' => null,
                    'state' => 'TN',
                    'processResult' => 'DocumentProcessOK',
                    'stateIssuerMismatch' => 'No',
                    'issuingJurisdictionAbbrv' => 'TN',
                    'hairColor' => null,
                    'dLIDNumberFormatted' => '000000000',
                    'transactionIdentifier' => '2cbb2fb1-b57f-4b8a-974c-09957866acd5',
                    'issuingJurisdictionCvt' => 'Tennessee',
                    'address2' => null,
                    'firstName' => 'John',
                    'testCard' => false,
                    'eyeColor' => 'Blue',
                    'isRealID' => 'Yes',
                    'heightCentimeters' => '178',
                    'address1' => '1144 Belle Pond Ave',
                ],
                'message' => null,
                'result' => true,
                'success' => true,
            ],
            'facial' => [
                'data' => [
                    'matchProbability' => 1,
                    'errorMessage' => null,
                    'livenessProbability' => 0.99,
                    'isLive' => true,
                    'matched' => true,
                    'livenessScore' => 4.36,
                    'matchScore' => 92,
                ],
                'success' => true,
                'message' => null,
                'result' => true,
            ],
            'ocr_match' => [
                'data' => [
                    'realIdMatchDetails' => [
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                        'similarityScore' => 100,
                    ],
                    'issueDateMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'dobMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'expirationDateMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'heightMatchDetails' => [
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                    ],
                    'dlRestrictionsMatchDetails' => [
                        'similarityScore' => 99,
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityThreshold' => 70,
                    ],
                    'dlClassMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                    ],
                    'isDlClassMatch' => true,
                    'isDobMatch' => true,
                    'isDocumentNumberMatch' => true,
                    'nameMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'isHeightMatch' => true,
                    'isExpirationDateMatch' => true,
                    'issuerNameMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'weightMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => null,
                        'details' => 'Missing value for comparison',
                    ],
                    'dlEndorsementMatchDetails' => [
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityScore' => 99,
                        'similarityThreshold' => 70,
                    ],
                    'isDlRestrictionsMatch' => true,
                    'isWeightMatch' => null,
                    'documentNumberMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'sexMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'isEyeColorMatch' => true,
                    'isIssuerNameMatch' => true,
                    'isRealIdMatch' => true,
                    'isSexMatch' => true,
                    'isCountryCodeMatch' => null,
                    'isAddressMatch' => true,
                    'isIssueDateMatch' => true,
                    'isNationalityMatch' => null,
                    'eyeColorMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                    ],
                    'addressMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                    ],
                    'isDlEndorsementMatch' => true,
                    'isNameMatch' => true,
                ],
                'success' => true,
                'message' => null,
                'result' => true,
            ],
            'ipqs' => [
                'addressDetails' => [
                    'data' => [
                        'transaction_details' => [
                            'leaked_shipping_email' => null,
                            'valid_billing_address' => true,
                            'phone_name_identity_match' => 'Unknown',
                            'is_prepaid_card' => null,
                            'shipping_phone_carrier' => null,
                            'fraudulent_behavior' => false,
                            'billing_address_distance' => [
                                'kilometers' => 540,
                                'miles' => 336,
                            ],
                            'shipping_phone_country' => null,
                            'email_name_identity_match' => 'Unknown',
                            'phone_email_identity_match' => 'Unknown',
                            'shipping_phone_country_code' => null,
                            'phone_address_identity_match' => 'Unknown',
                            'valid_shipping_phone' => null,
                            'risky_username' => null,
                            'bin_country' => null,
                            'address_email_identity_match' => 'Unknown',
                            'shipping_phone_line_type' => 'Unknown',
                            'billing_phone_country' => null,
                            'billing_phone_country_code' => null,
                            'risky_shipping_phone' => null,
                            'billing_phone_line_type' => 'Unknown',
                            'name_address_identity_match' => 'Match',
                            'valid_billing_email' => null,
                            'leaked_user_data' => null,
                            'valid_shipping_address' => null,
                            'risk_factors' => [
                                'IP address is frequently associated with abusive behavior.',
                                'IP address recently engaged in suspicious behavior.',
                            ],
                            'user_activity' => null,
                            'bin_type' => 'N/A',
                            'shipping_address_distance' => [
                                'miles' => 336,
                                'kilometers' => 540,
                            ],
                            'valid_shipping_email' => null,
                            'billing_phone_carrier' => null,
                            'risk_score' => 89,
                            'bin_bank_name' => null,
                            'leaked_billing_email' => null,
                            'valid_billing_phone' => null,
                            'risky_billing_phone' => null,
                        ],
                        'bot_status' => false,
                        'frequent_abuser' => false,
                        'mobile' => false,
                        'active_tor' => false,
                        'success' => true,
                        'security_scanner' => false,
                        'recent_abuse' => false,
                        'ISP' => 'Cisco OpenDNS',
                        'longitude' => -90.08999634,
                        'active_vpn' => true,
                        'timezone' => 'America/Chicago',
                        'is_crawler' => false,
                        'country_code' => 'US',
                        'region' => 'Tennessee',
                        'high_risk_attacks' => false,
                        'dynamic_connection' => false,
                        'message' => 'Success',
                        'fraud_score' => 75,
                        'latitude' => 35.18999863,
                        'city' => 'Memphis',
                        'proxy_networks' => [
                            'Enterprise plan required to view active proxy networks.',
                        ],
                        'tor' => false,
                        'organization' => 'Cisco Secure Access',
                        'zip_code' => 'N/A',
                        'vpn' => true,
                        'host' => '155.190.17.5',
                        'shared_connection' => true,
                        'request_id' => 'Xwk8skZLDI',
                        'trusted_network' => true,
                        'connection_type' => 'Corporate',
                        'ASN' => 36692,
                        'abuse_velocity' => 'low',
                        'proxy' => true,
                    ],
                    'message' => null,
                    'result' => true,
                    'success' => true,
                ],
                'fraudDetails' => [
                    'data' => [
                        'first_seen' => '2025-06-10 14:55:10',
                        'longitude' => -90.09,
                        'organization' => 'Cisco Secure Access',
                        'tor' => false,
                        'is_crawler' => false,
                        'timezone' => 'America/Chicago',
                        'mobile' => true,
                        'bot_status' => false,
                        'connection_type' => 'Data Center',
                        'recent_abuse' => false,
                        'ip_address' => '155.190.17.5',
                        'browser' => 'Mobile Safari 18.5',
                        'proxy' => true,
                        'region' => 'Tennessee',
                        'active_tor' => false,
                        'device_id' => 'af2bfff29fef3b2ca8e2f18adadd5f5367294a6de5f6699b051cbe8318949964',
                        'isp' => 'Cisco OpenDNS',
                        'model' => 'iPhone',
                        'brand' => 'Apple',
                        'city' => 'Memphis',
                        'operating_system' => 'iOS 18.5',
                        'last_seen' => '2025-06-10 14:55:10',
                        'active_vpn' => true,
                        'fraud_chance' => 43,
                        'vpn' => true,
                        'latitude' => 35.19,
                        'request_id' => 'Xwk1YIDjbD',
                        'country' => 'US',
                        'reasons' => [
                            'VPN connection or anonymizer detected',
                            'User has an abnormal connection',
                            'Abnormal browser settings detected',
                        ],
                        'unique' => true,
                    ],
                    'success' => true,
                    'message' => null,
                    'result' => true,
                ],
            ],
            'OCR' => [
                'data' => [
                    'firstName' => 'John',
                    'issuerName' => 'Tennessee',
                    'countryCode' => 'USA',
                    'eyeColor' => 'Blue',
                    'dateOfIssueFormatted' => '11/11/2024',
                    'documentRecognized' => 1,
                    'dateOfBirthFormatted' => '11/02/1981',
                    'dateOfExpiry' => '2032-11-11',
                    'documentNumber' => '000000000',
                    'dlEndorsement' => 'NONE',
                    'dlRestrictions' => 'NONE',
                    'faceImageBase64' => null,
                    'fullName' => 'John Alan Testington',
                    'dateOfExpiryFormatted' => '11/11/2032',
                    'height' => '178 cm',
                    'dlClass' => 'DM',
                    'fullDocumentImageBase64' => null,
                    'address' => '1144 Belle Pond Ave, Knoxville, TN 37932-2288',
                    'age' => '43',
                    'lastName' => 'Testington',
                    'dateOfIssue' => '2024-11-11',
                    'weightKilograms' => null,
                    'dateOfBirth' => '1981-11-02',
                    'sex' => 'M',
                    'isRealID' => 'yes',
                    'errorMessage' => null,
                    'placeOfBirth' => null,
                    'nationality' => null,
                ],
                'message' => null,
                'success' => true,
                'imageQualityFindings' => [],
                'result' => true,
            ],
            'ip' => '172.19.0.34',
            'is_showroom' => true,
        ];
    }

    public function idVerificationFlagTestB(): array
    {
        return [
            'idcheck' => [
                'data' => [
                    'dLIDNumberRaw' => '000000000',
                    'heightFeetInches' => "5' 10\"",
                    'extendedResultCode' => 'Y',
                    'driverClass' => 'DM',
                    'endorsements' => null,
                    'expirationDate' => '11/11/2032',
                    'docCategory' => 'DL',
                    'city' => 'Knoxville',
                    'postalCode' => '37932-2288',
                    'issueDate' => '11/11/2024',
                    'duplicateDate' => null,
                    'mediaType' => '2D',
                    'uniqueID' => 6211,
                    'expired' => 'No',
                    'docType' => null,
                    'dateOfBirth' => '1981-11-02',
                    'lastName' => 'Testington',
                    'middleName' => 'Alan',
                    'weightKilograms' => null,
                    'restrictions' => null,
                    'gender' => 'Male',
                    'weightPounds' => null,
                    'isDuplicate' => 'No',
                    'organDonor' => 'Yes',
                    'age' => 43,
                    'socialSecurity' => null,
                    'state' => 'TN',
                    'processResult' => 'DocumentProcessOK',
                    'stateIssuerMismatch' => 'No',
                    'issuingJurisdictionAbbrv' => 'TN',
                    'hairColor' => null,
                    'dLIDNumberFormatted' => '000000000',
                    'transactionIdentifier' => '2cbb2fb1-b57f-4b8a-974c-09957866acd5',
                    'issuingJurisdictionCvt' => 'Tennessee',
                    'address2' => null,
                    'firstName' => 'John',
                    'testCard' => false,
                    'eyeColor' => 'Blue',
                    'isRealID' => 'Yes',
                    'heightCentimeters' => '178',
                    'address1' => '1144 Belle Pond Ave',
                ],
                'message' => null,
                'result' => true,
                'success' => true,
            ],
            'facial' => [
                'data' => [
                    'matchProbability' => 1,
                    'errorMessage' => null,
                    'livenessProbability' => 0.99,
                    'isLive' => true,
                    'matched' => true,
                    'livenessScore' => 4.36,
                    'matchScore' => 92,
                ],
                'success' => true,
                'message' => null,
                'result' => true,
            ],
            'ocr_match' => [
                'data' => [
                    'realIdMatchDetails' => [
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                        'similarityScore' => 100,
                    ],
                    'issueDateMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'dobMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'expirationDateMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'heightMatchDetails' => [
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                    ],
                    'dlRestrictionsMatchDetails' => [
                        'similarityScore' => 99,
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityThreshold' => 70,
                    ],
                    'dlClassMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                    ],
                    'isDlClassMatch' => true,
                    'isDobMatch' => true,
                    'isDocumentNumberMatch' => true,
                    'nameMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'isHeightMatch' => true,
                    'isExpirationDateMatch' => true,
                    'issuerNameMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'weightMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => null,
                        'details' => 'Missing value for comparison',
                    ],
                    'dlEndorsementMatchDetails' => [
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityScore' => 99,
                        'similarityThreshold' => 70,
                    ],
                    'isDlRestrictionsMatch' => true,
                    'isWeightMatch' => null,
                    'documentNumberMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'sexMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'isEyeColorMatch' => true,
                    'isIssuerNameMatch' => true,
                    'isRealIdMatch' => true,
                    'isSexMatch' => true,
                    'isCountryCodeMatch' => null,
                    'isAddressMatch' => true,
                    'isIssueDateMatch' => true,
                    'isNationalityMatch' => null,
                    'eyeColorMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                    ],
                    'addressMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                    ],
                    'isDlEndorsementMatch' => true,
                    'isNameMatch' => true,
                ],
                'success' => true,
                'message' => null,
                'result' => true,
            ],
            'ipqs' => [
                'addressDetails' => [
                    'data' => [
                        'transaction_details' => [
                            'leaked_shipping_email' => null,
                            'valid_billing_address' => true,
                            'phone_name_identity_match' => 'Unknown',
                            'is_prepaid_card' => null,
                            'shipping_phone_carrier' => null,
                            'fraudulent_behavior' => false,
                            'billing_address_distance' => [
                                'kilometers' => 540,
                                'miles' => 336,
                            ],
                            'shipping_phone_country' => null,
                            'email_name_identity_match' => 'Unknown',
                            'phone_email_identity_match' => 'Unknown',
                            'shipping_phone_country_code' => null,
                            'phone_address_identity_match' => 'Unknown',
                            'valid_shipping_phone' => null,
                            'risky_username' => null,
                            'bin_country' => null,
                            'address_email_identity_match' => 'Unknown',
                            'shipping_phone_line_type' => 'Unknown',
                            'billing_phone_country' => null,
                            'billing_phone_country_code' => null,
                            'risky_shipping_phone' => null,
                            'billing_phone_line_type' => 'Unknown',
                            'name_address_identity_match' => 'Match',
                            'valid_billing_email' => null,
                            'leaked_user_data' => null,
                            'valid_shipping_address' => null,
                            'risk_factors' => [
                                'IP address is frequently associated with abusive behavior.',
                                'IP address recently engaged in suspicious behavior.',
                            ],
                            'user_activity' => null,
                            'bin_type' => 'N/A',
                            'shipping_address_distance' => [
                                'miles' => 336,
                                'kilometers' => 540,
                            ],
                            'valid_shipping_email' => null,
                            'billing_phone_carrier' => null,
                            'risk_score' => 89,
                            'bin_bank_name' => null,
                            'leaked_billing_email' => null,
                            'valid_billing_phone' => null,
                            'risky_billing_phone' => null,
                        ],
                        'bot_status' => false,
                        'frequent_abuser' => false,
                        'mobile' => false,
                        'active_tor' => false,
                        'success' => true,
                        'security_scanner' => false,
                        'recent_abuse' => false,
                        'ISP' => 'Cisco OpenDNS',
                        'longitude' => -90.08999634,
                        'active_vpn' => true,
                        'timezone' => 'America/Chicago',
                        'is_crawler' => false,
                        'country_code' => 'US',
                        'region' => 'Tennessee',
                        'high_risk_attacks' => false,
                        'dynamic_connection' => false,
                        'message' => 'Success',
                        'fraud_score' => 70,
                        'latitude' => 35.18999863,
                        'city' => 'Memphis',
                        'proxy_networks' => [
                            'Enterprise plan required to view active proxy networks.',
                        ],
                        'tor' => false,
                        'organization' => 'Cisco Secure Access',
                        'zip_code' => 'N/A',
                        'vpn' => true,
                        'host' => '155.190.17.5',
                        'shared_connection' => true,
                        'request_id' => 'Xwk8skZLDI',
                        'trusted_network' => true,
                        'connection_type' => 'Corporate',
                        'ASN' => 36692,
                        'abuse_velocity' => 'low',
                        'proxy' => true,
                    ],
                    'message' => null,
                    'result' => true,
                    'success' => true,
                ],
                'fraudDetails' => [
                    'data' => [
                        'first_seen' => '2025-06-10 14:55:10',
                        'longitude' => -90.09,
                        'organization' => 'Cisco Secure Access',
                        'tor' => false,
                        'is_crawler' => false,
                        'timezone' => 'America/Chicago',
                        'mobile' => true,
                        'bot_status' => false,
                        'connection_type' => 'Data Center',
                        'recent_abuse' => true,
                        'ip_address' => '155.190.17.5',
                        'browser' => 'Mobile Safari 18.5',
                        'proxy' => true,
                        'region' => 'Tennessee',
                        'active_tor' => false,
                        'device_id' => 'af2bfff29fef3b2ca8e2f18adadd5f5367294a6de5f6699b051cbe8318949964',
                        'isp' => 'Cisco OpenDNS',
                        'model' => 'iPhone',
                        'brand' => 'Apple',
                        'city' => 'Memphis',
                        'operating_system' => 'iOS 18.5',
                        'last_seen' => '2025-06-10 14:55:10',
                        'active_vpn' => true,
                        'fraud_chance' => 43,
                        'vpn' => true,
                        'latitude' => 35.19,
                        'request_id' => 'Xwk1YIDjbD',
                        'country' => 'US',
                        'reasons' => [
                            'VPN connection or anonymizer detected',
                            'User has an abnormal connection',
                            'Abnormal browser settings detected',
                        ],
                        'unique' => true,
                    ],
                    'success' => true,
                    'message' => null,
                    'result' => true,
                ],
            ],
            'OCR' => [
                'data' => [
                    'firstName' => 'John',
                    'issuerName' => 'Tennessee',
                    'countryCode' => 'USA',
                    'eyeColor' => 'Blue',
                    'dateOfIssueFormatted' => '11/11/2024',
                    'documentRecognized' => 1,
                    'dateOfBirthFormatted' => '11/02/1981',
                    'dateOfExpiry' => '2032-11-11',
                    'documentNumber' => '000000000',
                    'dlEndorsement' => 'NONE',
                    'dlRestrictions' => 'NONE',
                    'faceImageBase64' => null,
                    'fullName' => 'John Alan Testington',
                    'dateOfExpiryFormatted' => '11/11/2032',
                    'height' => '178 cm',
                    'dlClass' => 'DM',
                    'fullDocumentImageBase64' => null,
                    'address' => '1144 Belle Pond Ave, Knoxville, TN 37932-2288',
                    'age' => '43',
                    'lastName' => 'Testington',
                    'dateOfIssue' => '2024-11-11',
                    'weightKilograms' => null,
                    'dateOfBirth' => '1981-11-02',
                    'sex' => 'M',
                    'isRealID' => 'yes',
                    'errorMessage' => null,
                    'placeOfBirth' => null,
                    'nationality' => null,
                ],
                'message' => null,
                'success' => true,
                'imageQualityFindings' => [],
                'result' => true,
            ],
            'ip' => '172.19.0.34',
            'is_showroom' => true,
        ];
    }

    public function idVerificationFlagTestD(): array
    {
        return [
            'idcheck' => [
                'data' => [
                    'dLIDNumberRaw' => '000000000',
                    'heightFeetInches' => "5' 10\"",
                    'extendedResultCode' => 'Y',
                    'driverClass' => 'DM',
                    'endorsements' => null,
                    'expirationDate' => '11/11/2032',
                    'docCategory' => 'DL',
                    'city' => 'Knoxville',
                    'postalCode' => '37932-2288',
                    'issueDate' => '11/11/2024',
                    'duplicateDate' => null,
                    'mediaType' => '2D',
                    'uniqueID' => 6211,
                    'expired' => 'No',
                    'docType' => null,
                    'dateOfBirth' => '1981-11-02',
                    'lastName' => 'Testington',
                    'middleName' => 'Alan',
                    'weightKilograms' => null,
                    'restrictions' => null,
                    'gender' => 'Male',
                    'weightPounds' => null,
                    'isDuplicate' => 'No',
                    'organDonor' => 'Yes',
                    'age' => 43,
                    'socialSecurity' => null,
                    'state' => 'TN',
                    'processResult' => 'DocumentProcessOK',
                    'stateIssuerMismatch' => 'No',
                    'issuingJurisdictionAbbrv' => 'TN',
                    'hairColor' => null,
                    'dLIDNumberFormatted' => '000000000',
                    'transactionIdentifier' => '2cbb2fb1-b57f-4b8a-974c-09957866acd5',
                    'issuingJurisdictionCvt' => 'Tennessee',
                    'address2' => null,
                    'firstName' => 'John',
                    'testCard' => false,
                    'eyeColor' => 'Blue',
                    'isRealID' => 'Yes',
                    'heightCentimeters' => '178',
                    'address1' => '1144 Belle Pond Ave',
                ],
                'message' => null,
                'result' => true,
                'success' => true,
            ],
            'facial' => [
                'data' => [
                    'matchProbability' => 1,
                    'errorMessage' => null,
                    'livenessProbability' => 0.99,
                    'isLive' => true,
                    'matched' => true,
                    'livenessScore' => 4.36,
                    'matchScore' => 92,
                ],
                'success' => true,
                'message' => null,
                'result' => true,
            ],
            'ocr_match' => [
                'data' => [
                    'realIdMatchDetails' => [
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                        'similarityScore' => 100,
                    ],
                    'issueDateMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'dobMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'expirationDateMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'heightMatchDetails' => [
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                    ],
                    'dlRestrictionsMatchDetails' => [
                        'similarityScore' => 99,
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityThreshold' => 70,
                    ],
                    'dlClassMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                    ],
                    'isDlClassMatch' => true,
                    'isDobMatch' => true,
                    'isDocumentNumberMatch' => true,
                    'nameMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'isHeightMatch' => true,
                    'isExpirationDateMatch' => true,
                    'issuerNameMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'weightMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => null,
                        'details' => 'Missing value for comparison',
                    ],
                    'dlEndorsementMatchDetails' => [
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityScore' => 99,
                        'similarityThreshold' => 70,
                    ],
                    'isDlRestrictionsMatch' => true,
                    'isWeightMatch' => null,
                    'documentNumberMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'sexMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'isEyeColorMatch' => true,
                    'isIssuerNameMatch' => true,
                    'isRealIdMatch' => true,
                    'isSexMatch' => true,
                    'isCountryCodeMatch' => null,
                    'isAddressMatch' => true,
                    'isIssueDateMatch' => true,
                    'isNationalityMatch' => null,
                    'eyeColorMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                    ],
                    'addressMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                    ],
                    'isDlEndorsementMatch' => true,
                    'isNameMatch' => true,
                ],
                'success' => true,
                'message' => null,
                'result' => true,
            ],
            'ipqs' => [
                'addressDetails' => [
                    'data' => [
                        'transaction_details' => [
                            'leaked_shipping_email' => null,
                            'valid_billing_address' => true,
                            'phone_name_identity_match' => 'Unknown',
                            'is_prepaid_card' => null,
                            'shipping_phone_carrier' => null,
                            'fraudulent_behavior' => false,
                            'billing_address_distance' => [
                                'kilometers' => 540,
                                'miles' => 336,
                            ],
                            'shipping_phone_country' => null,
                            'email_name_identity_match' => 'Unknown',
                            'phone_email_identity_match' => 'Unknown',
                            'shipping_phone_country_code' => null,
                            'phone_address_identity_match' => 'Unknown',
                            'valid_shipping_phone' => null,
                            'risky_username' => null,
                            'bin_country' => null,
                            'address_email_identity_match' => 'Unknown',
                            'shipping_phone_line_type' => 'Unknown',
                            'billing_phone_country' => null,
                            'billing_phone_country_code' => null,
                            'risky_shipping_phone' => null,
                            'billing_phone_line_type' => 'Unknown',
                            'name_address_identity_match' => 'Match',
                            'valid_billing_email' => null,
                            'leaked_user_data' => null,
                            'valid_shipping_address' => null,
                            'risk_factors' => [
                                'IP address is frequently associated with abusive behavior.',
                                'IP address recently engaged in suspicious behavior.',
                            ],
                            'user_activity' => null,
                            'bin_type' => 'N/A',
                            'shipping_address_distance' => [
                                'miles' => 336,
                                'kilometers' => 540,
                            ],
                            'valid_shipping_email' => null,
                            'billing_phone_carrier' => null,
                            'risk_score' => 89,
                            'bin_bank_name' => null,
                            'leaked_billing_email' => null,
                            'valid_billing_phone' => null,
                            'risky_billing_phone' => null,
                        ],
                        'bot_status' => false,
                        'frequent_abuser' => false,
                        'mobile' => false,
                        'active_tor' => false,
                        'success' => true,
                        'security_scanner' => false,
                        'recent_abuse' => false,
                        'ISP' => 'Cisco OpenDNS',
                        'longitude' => -90.08999634,
                        'active_vpn' => true,
                        'timezone' => 'America/Chicago',
                        'is_crawler' => false,
                        'country_code' => 'US',
                        'region' => 'Tennessee',
                        'high_risk_attacks' => false,
                        'dynamic_connection' => false,
                        'message' => 'Success',
                        'fraud_score' => 0,
                        'latitude' => 35.18999863,
                        'city' => 'Memphis',
                        'proxy_networks' => [
                            'Enterprise plan required to view active proxy networks.',
                        ],
                        'tor' => false,
                        'organization' => 'Cisco Secure Access',
                        'zip_code' => 'N/A',
                        'vpn' => true,
                        'host' => '155.190.17.5',
                        'shared_connection' => true,
                        'request_id' => 'Xwk8skZLDI',
                        'trusted_network' => true,
                        'connection_type' => 'Corporate',
                        'ASN' => 36692,
                        'abuse_velocity' => 'low',
                        'proxy' => true,
                    ],
                    'message' => null,
                    'result' => true,
                    'success' => true,
                ],
                'fraudDetails' => [
                    'data' => [
                        'first_seen' => '2025-06-10 14:55:10',
                        'longitude' => -90.09,
                        'organization' => 'Cisco Secure Access',
                        'tor' => false,
                        'is_crawler' => false,
                        'timezone' => 'America/Chicago',
                        'mobile' => true,
                        'bot_status' => false,
                        'connection_type' => 'Data Center',
                        'recent_abuse' => false,
                        'ip_address' => '155.190.17.5',
                        'browser' => 'Mobile Safari 18.5',
                        'proxy' => true,
                        'region' => 'Tennessee',
                        'active_tor' => false,
                        'device_id' => 'af2bfff29fef3b2ca8e2f18adadd5f5367294a6de5f6699b051cbe8318949964',
                        'isp' => 'Cisco OpenDNS',
                        'model' => 'iPhone',
                        'brand' => 'Apple',
                        'city' => 'Memphis',
                        'operating_system' => 'iOS 18.5',
                        'last_seen' => '2025-06-10 14:55:10',
                        'active_vpn' => true,
                        'fraud_chance' => 75,
                        'vpn' => true,
                        'latitude' => 35.19,
                        'request_id' => 'Xwk1YIDjbD',
                        'country' => 'US',
                        'reasons' => [
                            'VPN connection or anonymizer detected',
                            'User has an abnormal connection',
                            'Abnormal browser settings detected',
                        ],
                        'unique' => true,
                    ],
                    'success' => true,
                    'message' => null,
                    'result' => true,
                ],
            ],
            'OCR' => [
                'data' => [
                    'firstName' => 'John',
                    'issuerName' => 'Tennessee',
                    'countryCode' => 'USA',
                    'eyeColor' => 'Blue',
                    'dateOfIssueFormatted' => '11/11/2024',
                    'documentRecognized' => 1,
                    'dateOfBirthFormatted' => '11/02/1981',
                    'dateOfExpiry' => '2032-11-11',
                    'documentNumber' => '000000000',
                    'dlEndorsement' => 'NONE',
                    'dlRestrictions' => 'NONE',
                    'faceImageBase64' => null,
                    'fullName' => 'John Alan Testington',
                    'dateOfExpiryFormatted' => '11/11/2032',
                    'height' => '178 cm',
                    'dlClass' => 'DM',
                    'fullDocumentImageBase64' => null,
                    'address' => '1144 Belle Pond Ave, Knoxville, TN 37932-2288',
                    'age' => '43',
                    'lastName' => 'Testington',
                    'dateOfIssue' => '2024-11-11',
                    'weightKilograms' => null,
                    'dateOfBirth' => '1981-11-02',
                    'sex' => 'M',
                    'isRealID' => 'yes',
                    'errorMessage' => null,
                    'placeOfBirth' => null,
                    'nationality' => null,
                ],
                'message' => null,
                'success' => true,
                'imageQualityFindings' => [],
                'result' => true,
            ],
            'ip' => '172.19.0.34',
            'is_showroom' => true,
        ];
    }

    public function idVerificationFlagC(): array
    {
        return [
            'idcheck' => [
                'data' => [
                    'dLIDNumberRaw' => '000000000',
                    'heightFeetInches' => "5' 10\"",
                    'extendedResultCode' => 'Y',
                    'driverClass' => 'DM',
                    'endorsements' => null,
                    'expirationDate' => '11/11/2032',
                    'docCategory' => 'DL',
                    'city' => 'Knoxville',
                    'postalCode' => '37932-2288',
                    'issueDate' => '11/11/2024',
                    'duplicateDate' => null,
                    'mediaType' => '2D',
                    'uniqueID' => 6211,
                    'expired' => 'No',
                    'docType' => null,
                    'dateOfBirth' => '1981-11-02',
                    'lastName' => 'Testington',
                    'middleName' => 'Alan',
                    'weightKilograms' => null,
                    'restrictions' => null,
                    'gender' => 'Male',
                    'weightPounds' => null,
                    'isDuplicate' => 'No',
                    'organDonor' => 'Yes',
                    'age' => 43,
                    'socialSecurity' => null,
                    'state' => 'TN',
                    'processResult' => 'DocumentProcessOK',
                    'stateIssuerMismatch' => 'No',
                    'issuingJurisdictionAbbrv' => 'TN',
                    'hairColor' => null,
                    'dLIDNumberFormatted' => '000000000',
                    'transactionIdentifier' => '2cbb2fb1-b57f-4b8a-974c-09957866acd5',
                    'issuingJurisdictionCvt' => 'Tennessee',
                    'address2' => null,
                    'firstName' => 'John',
                    'testCard' => false,
                    'eyeColor' => 'Blue',
                    'isRealID' => 'Yes',
                    'heightCentimeters' => '178',
                    'address1' => '1144 Belle Pond Ave',
                ],
                'message' => null,
                'result' => true,
                'success' => true,
            ],
            'facial' => [
                'data' => [
                    'matchProbability' => 1,
                    'errorMessage' => null,
                    'livenessProbability' => 0.99,
                    'isLive' => true,
                    'matched' => true,
                    'livenessScore' => 4.36,
                    'matchScore' => 92,
                ],
                'success' => true,
                'message' => null,
                'result' => true,
            ],
            'ocr_match' => [
                'data' => [
                    'realIdMatchDetails' => [
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                        'similarityScore' => 100,
                    ],
                    'issueDateMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'dobMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'expirationDateMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'heightMatchDetails' => [
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                    ],
                    'dlRestrictionsMatchDetails' => [
                        'similarityScore' => 99,
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityThreshold' => 70,
                    ],
                    'dlClassMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                    ],
                    'isDlClassMatch' => true,
                    'isDobMatch' => true,
                    'isDocumentNumberMatch' => true,
                    'nameMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'isHeightMatch' => true,
                    'isExpirationDateMatch' => true,
                    'issuerNameMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'weightMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => null,
                        'details' => 'Missing value for comparison',
                    ],
                    'dlEndorsementMatchDetails' => [
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityScore' => 99,
                        'similarityThreshold' => 70,
                    ],
                    'isDlRestrictionsMatch' => true,
                    'isWeightMatch' => null,
                    'documentNumberMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'sexMatchDetails' => [
                        'similarityScore' => 100,
                        'similarityThreshold' => 70,
                        'details' => 'Exact match between barcode and front data',
                    ],
                    'isEyeColorMatch' => true,
                    'isIssuerNameMatch' => true,
                    'isRealIdMatch' => true,
                    'isSexMatch' => true,
                    'isCountryCodeMatch' => null,
                    'isAddressMatch' => true,
                    'isIssueDateMatch' => true,
                    'isNationalityMatch' => null,
                    'eyeColorMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                    ],
                    'addressMatchDetails' => [
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 70,
                        'similarityScore' => 100,
                    ],
                    'isDlEndorsementMatch' => true,
                    'isNameMatch' => true,
                ],
                'success' => true,
                'message' => null,
                'result' => true,
            ],
            'ipqs' => [
                'addressDetails' => [
                    'data' => [
                        'transaction_details' => [
                            'leaked_shipping_email' => null,
                            'valid_billing_address' => true,
                            'phone_name_identity_match' => 'Unknown',
                            'is_prepaid_card' => null,
                            'shipping_phone_carrier' => null,
                            'fraudulent_behavior' => false,
                            'billing_address_distance' => [
                                'kilometers' => 540,
                                'miles' => 336,
                            ],
                            'shipping_phone_country' => null,
                            'email_name_identity_match' => 'Unknown',
                            'phone_email_identity_match' => 'Unknown',
                            'shipping_phone_country_code' => null,
                            'phone_address_identity_match' => 'Unknown',
                            'valid_shipping_phone' => null,
                            'risky_username' => null,
                            'bin_country' => null,
                            'address_email_identity_match' => 'Unknown',
                            'shipping_phone_line_type' => 'Unknown',
                            'billing_phone_country' => null,
                            'billing_phone_country_code' => null,
                            'risky_shipping_phone' => null,
                            'billing_phone_line_type' => 'Unknown',
                            'name_address_identity_match' => 'Match',
                            'valid_billing_email' => null,
                            'leaked_user_data' => null,
                            'valid_shipping_address' => null,
                            'risk_factors' => [
                                'IP address is frequently associated with abusive behavior.',
                                'IP address recently engaged in suspicious behavior.',
                            ],
                            'user_activity' => null,
                            'bin_type' => 'N/A',
                            'shipping_address_distance' => [
                                'miles' => 336,
                                'kilometers' => 540,
                            ],
                            'valid_shipping_email' => null,
                            'billing_phone_carrier' => null,
                            'risk_score' => 89,
                            'bin_bank_name' => null,
                            'leaked_billing_email' => null,
                            'valid_billing_phone' => null,
                            'risky_billing_phone' => null,
                        ],
                        'bot_status' => false,
                        'frequent_abuser' => false,
                        'mobile' => false,
                        'active_tor' => false,
                        'success' => true,
                        'security_scanner' => false,
                        'recent_abuse' => false,
                        'ISP' => 'Cisco OpenDNS',
                        'longitude' => -90.08999634,
                        'active_vpn' => true,
                        'timezone' => 'America/Chicago',
                        'is_crawler' => false,
                        'country_code' => 'US',
                        'region' => 'Tennessee',
                        'high_risk_attacks' => false,
                        'dynamic_connection' => false,
                        'message' => 'Success',
                        'fraud_score' => 70,
                        'latitude' => 35.18999863,
                        'city' => 'Memphis',
                        'proxy_networks' => [
                            'Enterprise plan required to view active proxy networks.',
                        ],
                        'tor' => false,
                        'organization' => 'Cisco Secure Access',
                        'zip_code' => 'N/A',
                        'vpn' => true,
                        'host' => '155.190.17.5',
                        'shared_connection' => true,
                        'request_id' => 'Xwk8skZLDI',
                        'trusted_network' => true,
                        'connection_type' => 'Corporate',
                        'ASN' => 36692,
                        'abuse_velocity' => 'low',
                        'proxy' => true,
                    ],
                    'message' => null,
                    'result' => true,
                    'success' => true,
                ],
                'fraudDetails' => [
                    'data' => [
                        'first_seen' => '2025-06-10 14:55:10',
                        'longitude' => -90.09,
                        'organization' => 'Cisco Secure Access',
                        'tor' => false,
                        'is_crawler' => false,
                        'timezone' => 'America/Chicago',
                        'mobile' => true,
                        'bot_status' => false,
                        'connection_type' => 'Data Center',
                        'recent_abuse' => true,
                        'ip_address' => '155.190.17.5',
                        'browser' => 'Mobile Safari 18.5',
                        'proxy' => true,
                        'region' => 'Tennessee',
                        'active_tor' => false,
                        'device_id' => 'af2bfff29fef3b2ca8e2f18adadd5f5367294a6de5f6699b051cbe8318949964',
                        'isp' => 'Cisco OpenDNS',
                        'model' => 'iPhone',
                        'brand' => 'Apple',
                        'city' => 'Memphis',
                        'operating_system' => 'iOS 18.5',
                        'last_seen' => '2025-06-10 14:55:10',
                        'active_vpn' => true,
                        'fraud_chance' => 43,
                        'vpn' => true,
                        'latitude' => 35.19,
                        'request_id' => 'Xwk1YIDjbD',
                        'country' => 'US',
                        'reasons' => [
                            'VPN connection or anonymizer detected',
                            'User has an abnormal connection',
                            'Abnormal browser settings detected',
                        ],
                        'unique' => true,
                    ],
                    'success' => true,
                    'message' => null,
                    'result' => true,
                ],
            ],
            'OCR' => [
                'data' => [
                    'firstName' => 'John',
                    'issuerName' => 'Tennessee',
                    'countryCode' => 'USA',
                    'eyeColor' => 'Blue',
                    'dateOfIssueFormatted' => '11/11/2024',
                    'documentRecognized' => 1,
                    'dateOfBirthFormatted' => '11/02/1981',
                    'dateOfExpiry' => '2032-11-11',
                    'documentNumber' => '000000000',
                    'dlEndorsement' => 'NONE',
                    'dlRestrictions' => 'NONE',
                    'faceImageBase64' => null,
                    'fullName' => 'John Alan Testington',
                    'dateOfExpiryFormatted' => '11/11/2032',
                    'height' => '178 cm',
                    'dlClass' => 'DM',
                    'fullDocumentImageBase64' => null,
                    'address' => '1144 Belle Pond Ave, Knoxville, TN 37932-2288',
                    'age' => '43',
                    'lastName' => 'Testington',
                    'dateOfIssue' => '2024-11-11',
                    'weightKilograms' => null,
                    'dateOfBirth' => '1981-11-02',
                    'sex' => 'M',
                    'isRealID' => 'yes',
                    'errorMessage' => null,
                    'placeOfBirth' => null,
                    'nationality' => null,
                ],
                'message' => null,
                'success' => true,
                'imageQualityFindings' => [],
                'result' => true,
            ],
            'ip' => '172.19.0.34',
            'is_showroom' => true,
        ];
    }

    public function testIdVerificationShowroom()
    {
        $verificationData = $this->idVerificationData();

        $isShowRoom = ! isset($verificationData['ipqs']);
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        // Get person name from lead entity
        $name = $lead->people->name;

        $verificationResults = IdVerificationService::processVerificationData($verificationData, $name, $isShowRoom);

        $this->assertEquals('fail', $verificationResults['status']);
        $this->assertArrayHasKey('flags', $verificationResults);
        $this->assertArrayHasKey('failures', $verificationResults);
        $this->assertArrayHasKey('results', $verificationResults);
        $this->assertArrayHasKey('message', $verificationResults);
        $this->assertArrayHasKey('ocMatch', $verificationResults);
        $this->assertArrayHasKey('status', $verificationResults);
    }

    public function testIdVerificationShowroomWithFlag()
    {
        $verificationData = $this->idVerificationFlag();

        $isShowRoom = ! isset($verificationData['ipqs']);
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        // Get person name from lead entity
        $name = $lead->people->name;

        $verificationResults = IdVerificationService::processVerificationData($verificationData, $name, $isShowRoom);
        $this->assertEquals('green', $verificationResults['status']);
        $this->assertArrayHasKey('flags', $verificationResults);
        $this->assertArrayHasKey('failures', $verificationResults);
        $this->assertArrayHasKey('results', $verificationResults);
        $this->assertArrayHasKey('message', $verificationResults);
        $this->assertArrayHasKey('ocMatch', $verificationResults);
        $this->assertArrayHasKey('status', $verificationResults);
    }

    public function testIdVerificationCaseA()
    {
        $verificationData = $this->idVerificationFlagTestA();

        $isShowRoom = ! isset($verificationData['ipqs']);
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        // Get person name from lead entity
        $name = $lead->people->name;

        $verificationResults = IdVerificationService::processVerificationData($verificationData, $name, $isShowRoom);
        $this->assertEquals('flag', $verificationResults['status']);
        $this->assertArrayHasKey('flags', $verificationResults);
        $this->assertArrayHasKey('failures', $verificationResults);
        $this->assertArrayHasKey('results', $verificationResults);
        $this->assertArrayHasKey('message', $verificationResults);
        $this->assertArrayHasKey('ocMatch', $verificationResults);
        $this->assertArrayHasKey('status', $verificationResults);
    }

    public function testIdVerificationCaseB()
    {
        $verificationData = $this->idVerificationFlagTestB();

        $isShowRoom = ! isset($verificationData['ipqs']);
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        // Get person name from lead entity
        $name = $lead->people->name;

        $verificationResults = IdVerificationService::processVerificationData($verificationData, $name, $isShowRoom);
        $this->assertEquals('green', $verificationResults['status']);
        $this->assertArrayHasKey('flags', $verificationResults);
        $this->assertArrayHasKey('failures', $verificationResults);
        $this->assertArrayHasKey('results', $verificationResults);
        $this->assertArrayHasKey('message', $verificationResults);
        $this->assertArrayHasKey('ocMatch', $verificationResults);
        $this->assertArrayHasKey('status', $verificationResults);
    }

    public function testIdVerificationCaseC()
    {
        $verificationData = $this->idVerificationFlagC();

        $isShowRoom = ! isset($verificationData['ipqs']);
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        // Get person name from lead entity
        $name = $lead->people->name;

        $verificationResults = IdVerificationService::processVerificationData($verificationData, $name, $isShowRoom);
        $this->assertEquals('green', $verificationResults['status']);
        $this->assertArrayHasKey('flags', $verificationResults);
        $this->assertArrayHasKey('failures', $verificationResults);
        $this->assertArrayHasKey('results', $verificationResults);
        $this->assertArrayHasKey('message', $verificationResults);
        $this->assertArrayHasKey('ocMatch', $verificationResults);
        $this->assertArrayHasKey('status', $verificationResults);
    }

    public function testIdVerificationCaseD()
    {
        $verificationData = $this->idVerificationFlagTestD();

        $isShowRoom = ! isset($verificationData['ipqs']);
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        // Get person name from lead entity
        $name = $lead->people->name;

        $verificationResults = IdVerificationService::processVerificationData($verificationData, $name, $isShowRoom);
        $this->assertEquals('flag', $verificationResults['status']);
        $this->assertArrayHasKey('flags', $verificationResults);
        $this->assertArrayHasKey('failures', $verificationResults);
        $this->assertArrayHasKey('results', $verificationResults);
        $this->assertArrayHasKey('message', $verificationResults);
        $this->assertArrayHasKey('ocMatch', $verificationResults);
        $this->assertArrayHasKey('status', $verificationResults);
    }

    public function testUpdatePeopleInfo()
    {
        $verificationData = $this->idVerificationData();

        $isShowRoom = ! isset($verificationData['ipqs']);
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $name = $lead->people->name;

        // Update people info
        PeopleService::updatePeopleInformation($lead->people, $verificationData);

        // Assert that the lead's people info has been updated
        $this->assertEquals('Keira Knightley', People::getById($lead->people->getId())->name);
    }

    /**
     * Regression: production payload where the back image was blurry
     * (idcheck.success=false / DocumentBadDevice) AND the license is expired
     * (OCR dateOfExpiry in the past). It previously resolved to green/"passed"
     * because the status engine only read the empty idcheck block. It must now
     * flag for manual review.
     */
    public function testBlurryBackImageWithExpiredIdDoesNotPass(): void
    {
        $verificationData = [
            'idcheck' => [
                'imageQualityFindings' => [
                    ['code' => 401, 'message' => 'Image submission error: Blurry document image submitted via /submit-back'],
                ],
                'message' => null,
                'success' => false,
                'data' => ['processResult' => 'DocumentBadDevice'],
                'result' => false,
            ],
            'ocr_match' => [
                'data' => [
                    'isIssueDateMatch' => null, 'isDobMatch' => null, 'isWeightMatch' => null,
                    'isDlClassMatch' => null, 'isRealIdMatch' => null, 'isAddressMatch' => null,
                    'isExpirationDateMatch' => null, 'isCountryCodeMatch' => null, 'isNameMatch' => null,
                    'isSexMatch' => null, 'isNationalityMatch' => null, 'isEyeColorMatch' => null,
                    'isHeightMatch' => null, 'isIssuerNameMatch' => null, 'isDocumentNumberMatch' => null,
                    'isDlEndorsementMatch' => null, 'isDlRestrictionsMatch' => null,
                ],
                'success' => true, 'message' => null, 'result' => false,
            ],
            'OCR' => [
                'data' => [
                    'dateOfExpiry' => '2025-10-20',
                    'dateOfExpiryFormatted' => '10/20/2025',
                    'firstName' => 'Thomas Mason Maurice',
                    'lastName' => 'Maurice',
                    'fullName' => 'Thomas Mason Maurice Maurice',
                    'documentNumber' => 'M3416495',
                ],
                'imageQualityFindings' => [], 'result' => true, 'message' => null, 'success' => true,
            ],
        ];

        $result = IdVerificationService::processVerificationData($verificationData, 'Thomas Mason Maurice', true);

        $this->assertNotSame('green', $result['status'], 'An unverifiable + expired ID must never pass');
        $this->assertSame('flag', $result['status']);
    }

    /**
     * Change 2 in isolation: idcheck succeeded and is not marked expired, but the
     * OCR expiry date is in the past — the OCR fallback must catch it.
     */
    public function testValidIdcheckButOcrExpiredFlags(): void
    {
        $verificationData = [
            'idcheck' => [
                'success' => true,
                'data' => ['processResult' => 'DocumentProcessOK', 'expired' => 'No'],
                'result' => true,
            ],
            'ocr_match' => ['data' => [], 'success' => true, 'result' => true],
            'OCR' => ['data' => ['dateOfExpiry' => '2020-01-01'], 'success' => true, 'result' => true],
        ];

        $result = IdVerificationService::processVerificationData($verificationData, 'Test User', true);

        $this->assertSame('flag', $result['status']);
        $this->assertContains('ID is expired', $result['flags']);
    }

    /**
     * Change 1 in isolation: idcheck failed (DocumentBadDevice) but the license
     * is NOT expired — still must flag, because we could not verify the document.
     */
    public function testUnverifiableIdcheckFlagsEvenWhenNotExpired(): void
    {
        $verificationData = [
            'idcheck' => [
                'success' => false,
                'data' => ['processResult' => 'DocumentBadDevice'],
                'result' => false,
            ],
            'ocr_match' => ['data' => [], 'success' => true, 'result' => false],
            'OCR' => ['data' => ['dateOfExpiry' => '2032-01-01'], 'success' => true, 'result' => true],
        ];

        $result = IdVerificationService::processVerificationData($verificationData, 'Test User', true);

        $this->assertNotSame('green', $result['status']);
        $this->assertSame('flag', $result['status']);
    }

    /**
     * Showroom payload (no ipqs block) where every Computer Vision field compares
     * cleanly. Overrides let each case flip individual match flags or blank out one
     * side of a comparison.
     */
    private function computerVisionPayload(array $matchOverrides = [], array $idCheckOverrides = [], array $ocrOverrides = []): array
    {
        return [
            'idcheck' => [
                'success' => true,
                'result' => true,
                'data' => array_merge([
                    'processResult' => 'DocumentProcessOK',
                    'expired' => 'no',
                    'firstName' => 'John',
                    'lastName' => 'Testington',
                    'dLIDNumberRaw' => '000000000',
                    'dateOfBirth' => '1981-11-02',
                    'gender' => 'Male',
                    'address1' => '1144 Belle Pond Ave',
                    'expirationDate' => '11/11/2032',
                    'issuingJurisdictionCvt' => 'Tennessee',
                    'issueDate' => '11/11/2024',
                    'isRealID' => 'Yes',
                    'heightCentimeters' => '178',
                    'driverClass' => 'DM',
                ], $idCheckOverrides),
            ],
            'ocr_match' => [
                'success' => true,
                'result' => true,
                'data' => array_merge([
                    'isNameMatch' => true,
                    'isDocumentNumberMatch' => true,
                    'isDobMatch' => true,
                    'isSexMatch' => true,
                    'isAddressMatch' => true,
                    'isExpirationDateMatch' => true,
                    'isIssuerNameMatch' => true,
                    'isIssueDateMatch' => true,
                    'isRealIdMatch' => true,
                    'isHeightMatch' => true,
                    'isDlClassMatch' => true,
                ], $matchOverrides),
            ],
            'OCR' => [
                'success' => true,
                'result' => true,
                'data' => array_merge([
                    'fullName' => 'John Testington',
                    'documentNumber' => '000000000',
                    'dateOfBirthFormatted' => '11/02/1981',
                    'sex' => 'M',
                    'address' => '1144 Belle Pond Ave, Knoxville, TN 37932',
                    'dateOfExpiryFormatted' => '11/11/2032',
                    'dateOfExpiry' => '2032-11-11',
                    'issuerName' => 'Tennessee',
                    'dateOfIssueFormatted' => '11/11/2024',
                    'isRealID' => 'yes',
                    'height' => '178 cm',
                    'dlClass' => 'DM',
                ], $ocrOverrides),
            ],
        ];
    }

    private function statusFor(array $verificationData): string
    {
        return IdVerificationService::processVerificationData($verificationData, 'Test User', true)['status'];
    }

    public function testEveryComputerVisionFieldMatchingPasses(): void
    {
        $this->assertSame('green', $this->statusFor($this->computerVisionPayload()));
    }

    public function testUpToThreeMismatchesOnlyFlag(): void
    {
        $this->assertSame('flag', $this->statusFor($this->computerVisionPayload(['isHeightMatch' => false])));

        $this->assertSame('flag', $this->statusFor($this->computerVisionPayload([
            'isHeightMatch' => false,
            'isDlClassMatch' => false,
            'isIssueDateMatch' => false,
        ])));
    }

    public function testFourMismatchesFail(): void
    {
        $status = $this->statusFor($this->computerVisionPayload([
            'isHeightMatch' => false,
            'isDlClassMatch' => false,
            'isIssueDateMatch' => false,
            'isIssuerNameMatch' => false,
        ]));

        $this->assertSame('fail', $status);
    }

    /**
     * Incomplete fields are yellow on their own and never push a mismatch count over
     * the failure threshold — 3 mismatches stay a flag no matter how many fields
     * could not be compared.
     */
    public function testIncompleteFieldsDoNotCountTowardsTheFailureThreshold(): void
    {
        $status = $this->statusFor($this->computerVisionPayload([
            'isHeightMatch' => false,
            'isDlClassMatch' => false,
            'isIssueDateMatch' => false,
            'isNameMatch' => null,
            'isDobMatch' => null,
            'isSexMatch' => null,
            'isAddressMatch' => null,
            'isExpirationDateMatch' => null,
        ]));

        $this->assertSame('flag', $status);
    }

    public function testBlankComparisonSideFlagsEvenWhenTheFlagSaysMatch(): void
    {
        $status = $this->statusFor($this->computerVisionPayload(
            idCheckOverrides: ['dLIDNumberRaw' => '   ']
        ));

        $this->assertSame('flag', $status);
    }

    /**
     * Real IDs, height and driver class are not printed by every jurisdiction. Their
     * absence must stay green, or a whole state's clean scans would go yellow forever.
     */
    public function testMissingOptionalFieldsStayGreen(): void
    {
        $payload = $this->computerVisionPayload([
            'isRealIdMatch' => null,
            'isHeightMatch' => null,
            'isDlClassMatch' => null,
        ], [
            'isRealID' => null,
            'heightCentimeters' => null,
            'driverClass' => null,
        ], [
            'isRealID' => null,
            'height' => null,
            'dlClass' => null,
        ]);

        $this->assertSame('green', $this->statusFor($payload));
    }

    /**
     * The score counts fields that actually compared and matched, over all 11 — an
     * optional field nobody could compare must not inflate it.
     */
    public function testMatchScoreOnlyCreditsFieldsThatActuallyCompared(): void
    {
        $all = IdVerificationService::processVerificationData($this->computerVisionPayload(), 'Test User', true);
        $this->assertSame(100.0, round($all['results']['ocr_required_matches'], 2));

        $payload = $this->computerVisionPayload(
            ['isHeightMatch' => null, 'isDlClassMatch' => false],
            ['heightCentimeters' => null],
            ['height' => null]
        );

        $partial = IdVerificationService::processVerificationData($payload, 'Test User', true);

        $this->assertSame(81.82, round($partial['results']['ocr_required_matches'], 2));
    }

    public function testBooleanFalseOnBothSidesIsAComparisonNotABlank(): void
    {
        $payload = $this->computerVisionPayload(
            idCheckOverrides: ['isRealID' => false],
            ocrOverrides: ['isRealID' => false]
        );

        $this->assertSame('green', $this->statusFor($payload));
    }
}
