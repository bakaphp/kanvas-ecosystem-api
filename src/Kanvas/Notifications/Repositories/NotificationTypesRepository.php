<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Exceptions\ExceptionsModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Notifications\Models\NotificationTypes;

class NotificationTypesRepository
{
    /**
     * Retrieve email template by verb and event
     * @psalm-suppress MixedReturnStatement
     */
    public static function getTemplateByVerbAndEvent(
        string $verb,
        string $event,
        AppInterface $app
    ): NotificationTypes
    {
        /**
         * whereIn not working properly. giving error.
         */
        try {
            $query = NotificationTypes::notDeleted()
                ->where('apps_id', $app->getId())
                ->where('verb', $verb)
                ->where('event', $event);

            return $query->firstOrFail();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException('Template not found for verb ' . $verb . ' and event ' . $event);
        }
    }
}
