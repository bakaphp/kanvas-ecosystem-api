<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\ParkingApplications\Enums;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Catalog of every custom-field key the 9-step parking-provider wizard writes onto the Lead
 * carrying the application. `ValidateParkingApplicationStepAction` rejects any key outside this
 * catalog — `custom_fields` otherwise accepts any string key, so a typo would silently not save.
 */
enum ParkingApplicationFieldEnum: string
{
    case STATUS = 'parking_application_status';
    case STATUS_REASON = 'parking_application_status_reason';
    case STEP = 'parking_application_step';
    case REVIEWED_BY = 'parking_application_reviewed_by';
    case REVIEWED_AT = 'parking_application_reviewed_at';
    case NUMBER = 'parking_application_number';
    case PRODUCT_ID = 'parking_application_product_id';

    /**
     * Server-stamped by the Phase 16 acceptance flow (mirrors StampTermsAcceptanceActivity), not
     * applicant-writable. Kept out of CONTRACT_FIELDS on purpose so step() returns null for both
     * and ValidateParkingApplicationStepAction rejects them like any other bookkeeping key.
     */
    case CONTRACT_ACCEPTED_AT = 'parking_application_contract_accepted_at';
    case CONTRACT_ACCEPTANCE_IP = 'parking_application_contract_acceptance_ip';

    case APPLICANT_TYPE = 'parking_application_applicant_type';
    case NATIONAL_ID = 'parking_application_national_id';
    case FULL_NAME = 'parking_application_full_name';
    case PHONE = 'parking_application_phone';
    case EMAIL = 'parking_application_email';
    case RNC = 'parking_application_rnc';
    case LEGAL_NAME = 'parking_application_legal_name';
    case COMMERCIAL_NAME = 'parking_application_commercial_name';
    case LEGAL_REPRESENTATIVE = 'parking_application_legal_representative';
    case PARKING_NAME = 'parking_application_parking_name';

    case PARKING_TYPE = 'parking_application_parking_type';
    case PARKING_TYPE_OTHER = 'parking_application_parking_type_other';
    case STRUCTURE = 'parking_application_structure';
    case HAS_LIGHTING = 'parking_application_has_lighting';
    case HAS_CAMERAS = 'parking_application_has_cameras';
    case CAMERA_COUNT = 'parking_application_camera_count';
    case HAS_GUARD = 'parking_application_has_guard';
    case HAS_ROOF = 'parking_application_has_roof';
    case HAS_ACCESS_CONTROL = 'parking_application_has_access_control';
    case HAS_RESTROOMS = 'parking_application_has_restrooms';
    case IS_24_7_SECURITY = 'parking_application_is_24_7_security';

    case ADDRESS = 'parking_application_address';
    case CITY = 'parking_application_city';
    case PROVINCE = 'parking_application_province';
    case REFERENCES = 'parking_application_references';
    case LATITUDE = 'parking_application_latitude';
    case LONGITUDE = 'parking_application_longitude';
    case COORDINATES_PENDING_VALIDATION = 'parking_application_coordinates_pending_validation';

    case CAPACITY_TOTAL = 'parking_application_capacity_total';
    case CAPACITY_LIGHT_VEHICLES = 'parking_application_capacity_light_vehicles';
    case CAPACITY_MOTORCYCLES = 'parking_application_capacity_motorcycles';
    case CAPACITY_DISABILITY = 'parking_application_capacity_disability';
    case NUMBERED_SPACES = 'parking_application_numbered_spaces';
    case CAPACITY_NOTES = 'parking_application_capacity_notes';

    /**
     * Photos themselves are Lead files (attachFilesToLead), not custom fields. This is the one
     * key the photos step still needs in the catalog: which uploaded file is the cover.
     */
    case COVER_PHOTO_UUID = 'parking_application_cover_photo_uuid';

    case IS_24_7 = 'parking_application_is_24_7';
    case SCHEDULE = 'parking_application_schedule';
    case CLOSURES = 'parking_application_closures';
    case PAYMENT_METHODS = 'parking_application_payment_methods';

    case RATE_HOURLY = 'parking_application_rate_hourly';
    case RATE_MINIMUM_ENTRY = 'parking_application_rate_minimum_entry';
    case RATE_OVERNIGHT = 'parking_application_rate_overnight';
    case RATE_MONTHLY = 'parking_application_rate_monthly';

    case BANK_NAME = 'parking_application_bank_name';
    case BANK_ACCOUNT_TYPE = 'parking_application_bank_account_type';
    case BANK_ACCOUNT_NUMBER = 'parking_application_bank_account_number';
    case BANK_ACCOUNT_HOLDER = 'parking_application_bank_account_holder';
    case SETTLEMENT_EMAIL = 'parking_application_settlement_email';

    case CONTRACT_VERSION = 'parking_application_contract_version';

    /**
     * The step-10 checkbox. Distinct from CONTRACT_ACCEPTED_AT — this is the applicant's
     * acceptance intent; the timestamp and IP are stamped server-side once this is true.
     */
    case CONTRACT_ACCEPTED = 'parking_application_contract_accepted';

    public function step(): ?ParkingApplicationStepEnum
    {
        return match (true) {
            in_array($this, self::IDENTIFICATION_FIELDS, true) => ParkingApplicationStepEnum::IDENTIFICATION,
            in_array($this, self::CHARACTERISTICS_FIELDS, true) => ParkingApplicationStepEnum::CHARACTERISTICS,
            in_array($this, self::LOCATION_FIELDS, true) => ParkingApplicationStepEnum::LOCATION,
            in_array($this, self::CAPACITY_FIELDS, true) => ParkingApplicationStepEnum::CAPACITY,
            in_array($this, self::PHOTOS_FIELDS, true) => ParkingApplicationStepEnum::PHOTOS,
            in_array($this, self::OPERATIONS_FIELDS, true) => ParkingApplicationStepEnum::OPERATIONS,
            in_array($this, self::RATES_FIELDS, true) => ParkingApplicationStepEnum::RATES,
            in_array($this, self::BANKING_FIELDS, true) => ParkingApplicationStepEnum::BANKING,
            in_array($this, self::CONTRACT_FIELDS, true) => ParkingApplicationStepEnum::CONTRACT,
            default => null,
        };
    }

    public function type(): ParkingApplicationFieldTypeEnum
    {
        return match (true) {
            in_array($this, self::INTEGER_TYPE_FIELDS, true) => ParkingApplicationFieldTypeEnum::INTEGER,
            in_array($this, self::DECIMAL_TYPE_FIELDS, true) => ParkingApplicationFieldTypeEnum::DECIMAL,
            in_array($this, self::BOOLEAN_TYPE_FIELDS, true) => ParkingApplicationFieldTypeEnum::BOOLEAN,
            in_array($this, self::ENUM_TYPE_FIELDS, true) => ParkingApplicationFieldTypeEnum::ENUM,
            in_array($this, self::EMAIL_TYPE_FIELDS, true) => ParkingApplicationFieldTypeEnum::EMAIL,
            in_array($this, self::DATE_TIME_TYPE_FIELDS, true) => ParkingApplicationFieldTypeEnum::DATE_TIME,
            $this === self::SCHEDULE => ParkingApplicationFieldTypeEnum::SCHEDULE,
            $this === self::CLOSURES => ParkingApplicationFieldTypeEnum::CLOSURES,
            $this === self::PAYMENT_METHODS => ParkingApplicationFieldTypeEnum::PAYMENT_METHODS,
            in_array($this, self::TEXT_TYPE_FIELDS, true) => ParkingApplicationFieldTypeEnum::TEXT,
            default => ParkingApplicationFieldTypeEnum::STRING,
        };
    }

    /**
     * The fixed vocabulary for enum-like keys. Also covers PAYMENT_METHODS, whose type is its
     * own PAYMENT_METHODS case (an array of these, not a single value).
     */
    public function options(): ?array
    {
        return match ($this) {
            self::APPLICANT_TYPE => ['natural', 'juridica'],
            self::PARKING_TYPE => ['public', 'private', 'commercial', 'residential', 'mixed', 'other'],
            self::STRUCTURE => ['open_lot', 'covered', 'multilevel', 'underground'],
            self::BANK_ACCOUNT_TYPE => ['savings', 'checking'],
            self::PAYMENT_METHODS => ['movipass', 'cash'],
            default => null,
        };
    }

    public function isSensitive(): bool
    {
        return in_array($this, self::BANKING_FIELDS, true);
    }

    public function readFrom(Model $entity): mixed
    {
        $value = $entity->get($this->value);

        if ($value === null || ! $this->isSensitive()) {
            return $value;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            return $value;
        }
    }

    public function writeTo(Model $entity, mixed $value): void
    {
        if ($this->isSensitive() && $value !== null) {
            $value = Crypt::encryptString((string) $value);
        }

        $entity->set($this->value, $value);
    }

    private const array IDENTIFICATION_FIELDS = [
        self::APPLICANT_TYPE,
        self::NATIONAL_ID,
        self::FULL_NAME,
        self::PHONE,
        self::EMAIL,
        self::RNC,
        self::LEGAL_NAME,
        self::COMMERCIAL_NAME,
        self::LEGAL_REPRESENTATIVE,
        self::PARKING_NAME,
    ];

    private const array CHARACTERISTICS_FIELDS = [
        self::PARKING_TYPE,
        self::PARKING_TYPE_OTHER,
        self::STRUCTURE,
        self::HAS_LIGHTING,
        self::HAS_CAMERAS,
        self::CAMERA_COUNT,
        self::HAS_GUARD,
        self::HAS_ROOF,
        self::HAS_ACCESS_CONTROL,
        self::HAS_RESTROOMS,
        self::IS_24_7_SECURITY,
    ];

    private const array LOCATION_FIELDS = [
        self::ADDRESS,
        self::CITY,
        self::PROVINCE,
        self::REFERENCES,
        self::LATITUDE,
        self::LONGITUDE,
        self::COORDINATES_PENDING_VALIDATION,
    ];

    private const array CAPACITY_FIELDS = [
        self::CAPACITY_TOTAL,
        self::CAPACITY_LIGHT_VEHICLES,
        self::CAPACITY_MOTORCYCLES,
        self::CAPACITY_DISABILITY,
        self::NUMBERED_SPACES,
        self::CAPACITY_NOTES,
    ];

    private const array PHOTOS_FIELDS = [
        self::COVER_PHOTO_UUID,
    ];

    private const array OPERATIONS_FIELDS = [
        self::IS_24_7,
        self::SCHEDULE,
        self::CLOSURES,
        self::PAYMENT_METHODS,
    ];

    private const array RATES_FIELDS = [
        self::RATE_HOURLY,
        self::RATE_MINIMUM_ENTRY,
        self::RATE_OVERNIGHT,
        self::RATE_MONTHLY,
    ];

    private const array BANKING_FIELDS = [
        self::BANK_NAME,
        self::BANK_ACCOUNT_TYPE,
        self::BANK_ACCOUNT_NUMBER,
        self::BANK_ACCOUNT_HOLDER,
        self::SETTLEMENT_EMAIL,
    ];

    private const array CONTRACT_FIELDS = [
        self::CONTRACT_VERSION,
        self::CONTRACT_ACCEPTED,
    ];

    private const array INTEGER_TYPE_FIELDS = [
        self::CAMERA_COUNT,
        self::CAPACITY_TOTAL,
        self::CAPACITY_LIGHT_VEHICLES,
        self::CAPACITY_MOTORCYCLES,
        self::CAPACITY_DISABILITY,
    ];

    private const array DECIMAL_TYPE_FIELDS = [
        self::LATITUDE,
        self::LONGITUDE,
        self::RATE_HOURLY,
        self::RATE_MINIMUM_ENTRY,
        self::RATE_OVERNIGHT,
        self::RATE_MONTHLY,
    ];

    private const array BOOLEAN_TYPE_FIELDS = [
        self::HAS_LIGHTING,
        self::HAS_CAMERAS,
        self::HAS_GUARD,
        self::HAS_ROOF,
        self::HAS_ACCESS_CONTROL,
        self::HAS_RESTROOMS,
        self::IS_24_7_SECURITY,
        self::COORDINATES_PENDING_VALIDATION,
        self::NUMBERED_SPACES,
        self::IS_24_7,
        self::CONTRACT_ACCEPTED,
    ];

    private const array ENUM_TYPE_FIELDS = [
        self::APPLICANT_TYPE,
        self::PARKING_TYPE,
        self::STRUCTURE,
        self::BANK_ACCOUNT_TYPE,
    ];

    private const array EMAIL_TYPE_FIELDS = [
        self::EMAIL,
        self::SETTLEMENT_EMAIL,
    ];

    private const array DATE_TIME_TYPE_FIELDS = [
        self::CONTRACT_ACCEPTED_AT,
    ];

    private const array TEXT_TYPE_FIELDS = [
        self::ADDRESS,
        self::REFERENCES,
        self::CAPACITY_NOTES,
    ];
}
