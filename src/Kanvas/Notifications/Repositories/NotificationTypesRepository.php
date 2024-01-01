<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Repositories;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Notifications\Models\NotificationTypes;

class NotificationTypesRepository
{
    /**
     * Retrieve email template by verb and event
     * @psalm-suppress MixedReturnStatement
     */
    public static function getTemplateByVerbAndEvent(
        AppInterface $app,
        string $verb,
        string $event,
    ): NotificationTypes {
        /**
         * whereIn not working properly. giving error.
         */
        try {
            return NotificationTypes::notDeleted()
                ->fromApp($app)
                ->where('verb', $verb)
                ->where('event', $event)
                ->firstOrFail();
        } catch (EloquentModelNotFoundException $e) {
            throw new ModelNotFoundException('Template not found for verb ' . $verb . ' and event ' . $event);
        }
    }
}
