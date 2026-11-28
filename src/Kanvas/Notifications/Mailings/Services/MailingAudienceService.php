<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Services;

use Baka\Support\AllowedClass;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Mailings\Contracts\AudienceResolver;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingAudienceEnum;
use Kanvas\Users\Models\Users;

class MailingAudienceService
{
    public function __construct(
        private readonly Apps $app,
        private readonly ?Companies $company,
    ) {
    }

    public function resolve(AppUserMailingAudienceEnum $audience, array $criteria): array
    {
        $userIds = $this->resolver($audience)->resolve($this->app, $this->company, $criteria);
        $partition = new AppUserMailingEligibilityService($this->app)->partition($userIds);

        return [
            'total' => count($userIds),
            'eligible' => $partition['eligible'],
            'excluded' => $partition['excluded'],
        ];
    }

    public function maskedSample(array $eligibleIds, int $size = 5): array
    {
        return Users::query()
            ->whereIn('id', array_slice($eligibleIds, 0, $size))
            ->pluck('email')
            ->map(fn (string $email): string => $this->maskEmail($email))
            ->values()
            ->all();
    }

    private function resolver(AppUserMailingAudienceEnum $audience): AudienceResolver
    {
        $class = AllowedClass::resolve(config('mailings.audiences.' . $audience->value), AudienceResolver::class);

        if ($class === null) {
            throw new ValidationException("No audience resolver is configured for \"{$audience->value}\".");
        }

        return new $class();
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return substr($local, 0, 2) . '***@' . $domain;
    }
}
