<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Enums;

enum ConfigurationEnum: string
{
    case TWILIO_ACCOUNT_SID = 'TWILIO_ACCOUNT_SID';
    case TWILIO_AUTH_TOKEN = 'TWILIO_AUTH_TOKEN';
    case TWILIO_VERIFICATION_SID = 'TWILIO_VERIFICATION_SID';
    case TWILIO_VERIFICATION_SKIP_USERS = 'TWILIO_VERIFICATION_SKIP_USERS';
    case TWILIO_2FA_SEND_RATE_LIMIT = 'TWILIO_2FA_SEND_RATE_LIMIT';
    case TWILIO_2FA_VERIFY_RATE_LIMIT = 'TWILIO_2FA_VERIFY_RATE_LIMIT';
    case TWILIO_2FA_SEND_RATE_LIMIT_DECAY = 'TWILIO_2FA_SEND_RATE_LIMIT_DECAY';
    case TWILIO_2FA_VERIFY_RATE_LIMIT_DECAY = 'TWILIO_2FA_VERIFY_RATE_LIMIT_DECAY';
    case TWILIO_2FA_SEND_COOLDOWN_SECONDS = 'TWILIO_2FA_SEND_COOLDOWN_SECONDS';
    case TWILIO_FROM_PHONE_NUMBER = 'twilio_from_phone_number';
    case TWILIO_PHONE_NUMBER = 'twilio_phone_number';
    case TWILIO_MESSAGING_SERVICE_SID = 'twilio_messaging_service_sid';
    case TWILIO_SENDER_ACCOUNT_SID = 'twilio_sender_account_sid';
    case TWILIO_ALLOWED_FROM_PHONE_NUMBERS = 'twilio_allowed_from_phone_numbers';
    case TWILIO_A2P_REGISTRATION_STATUS = 'twilio_a2p_registration_status';
    case TWILIO_ENFORCE_A2P_REGISTRATION = 'twilio_enforce_a2p_registration';
    case TWILIO_MAX_MESSAGE_BODY_LENGTH = 'twilio_max_message_body_length';
    case TWILIO_MMS_BATCH_SIZE = 'twilio-mms-batch-size';
    case TWILIO_MMS_MAX_TOTAL_MEDIA = 'twilio-mms-max-total-media';
}
