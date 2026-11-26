<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Services;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class EventReportAccess
{
    public const string VIEW_ABILITY = 'view-event-reports';
    public const string GATE_ABILITY = 'validate-pass';

    public static function authorizeBookings(UserInterface $user): void
    {
        self::authorize($user, [self::VIEW_ABILITY, self::GATE_ABILITY]);
    }

    public static function authorizeStats(UserInterface $user): void
    {
        self::authorize($user, [self::VIEW_ABILITY]);
    }

    private static function authorize(UserInterface $user, array $abilities): void
    {
        if ($user->isAdmin() || Gate::forUser($user)->any($abilities)) {
            return;
        }

        throw new AuthorizationException();
    }
}
