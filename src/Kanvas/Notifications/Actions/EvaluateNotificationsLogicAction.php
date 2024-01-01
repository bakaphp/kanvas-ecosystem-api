<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Notifications\Models\NotificationTypesMessageLogic;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class EvaluateNotificationsLogicAction
{
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected NotificationTypesMessageLogic $notificationsLogic,
        protected array $message
    ) {
    }

    /**
     * Evaluate Notifications Logic
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(): bool
    {
        $expressionLanguage = new ExpressionLanguage();

        $logic = json_decode($this->notificationsLogic->logic);
        $conditions = (string) $logic->conditions;

        // TODO Figure out how to embed date on the logic record
        $dateInTenMins = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $dateNow = date('Y-m-d H:i:s');

        return $expressionLanguage->evaluate(
            $conditions,
            [
                'user' => $this->user,
                'app' => $this->app,
                'message' => (object) $this->message,
            ]
        );
    }
}
