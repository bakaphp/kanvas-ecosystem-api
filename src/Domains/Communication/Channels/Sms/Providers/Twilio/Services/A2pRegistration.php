<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio\Services;

use Baka\Contracts\CompanyInterface;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Enums\ConfigurationEnum;

/**
 * US carriers drop texts from an unregistered 10DLC sender (Twilio error 30034). A tenant that
 * enforces registration has to be stopped before Twilio is called, not told afterwards.
 */
final class A2pRegistration
{
    private const array APPROVED_STATUSES = ['approved', 'registered', 'verified', 'active'];

    /**
     * @param string|null $from the sender number; null for a Messaging Service, which may hold US
     *                          numbers whatever its own identity is
     *
     * @return string|null the registration status that blocks sending, or null when nothing does
     */
    public static function blockingStatus(CompanyInterface $company, ?string $from): ?string
    {
        if ($from !== null && ! str_starts_with($from, '+1')) {
            return null;
        }

        if (! $company->getBool(ConfigurationEnum::TWILIO_ENFORCE_A2P_REGISTRATION->value)) {
            return null;
        }

        $status = strtolower(trim((string) ($company->get(ConfigurationEnum::TWILIO_A2P_REGISTRATION_STATUS->value) ?? '')));

        return in_array($status, self::APPROVED_STATUSES, true) ? null : ($status !== '' ? $status : 'missing');
    }
}
