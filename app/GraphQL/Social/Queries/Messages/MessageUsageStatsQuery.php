<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Messages;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Repositories\MessageUsageStatsRepository;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class MessageUsageStatsQuery
{
    public function userStats(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $days = min((int) ($args['days'] ?? 7), 30);
        $messageType = isset($args['message_type_id'])
            ? MessageType::getById((int) $args['message_type_id'], $app)
            : null;

        return MessageUsageStatsRepository::getUserDailyStats(
            app: $app,
            user: $user,
            days: $days,
            messageType: $messageType,
        );
    }

    public function companyStats(mixed $rootValue, array $args): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $days = min((int) ($args['days'] ?? 7), 30);
        $messageType = isset($args['message_type_id'])
            ? MessageType::getById((int) $args['message_type_id'], $app)
            : null;

        return MessageUsageStatsRepository::getCompanyDailyStats(
            app: $app,
            company: $user->getCurrentCompany(),
            days: $days,
            messageType: $messageType,
        );
    }
}
