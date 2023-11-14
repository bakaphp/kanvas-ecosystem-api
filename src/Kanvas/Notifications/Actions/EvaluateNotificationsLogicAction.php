<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Kanvas\Notifications\Models\NotificationTypesMessageLogic;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class EvaluateNotificationsLogicAction
{
    public function __construct(
        public NotificationTypesMessageLogic $notificationsLogic,
        public array $message
    ) {
    }

    /**
     * Evaluate Notifications Logic
     */
    public function execute(): bool
    {
        $expressionLanguage = new ExpressionLanguage();

        $logic = json_decode($this->notificationsLogic->logic);
        $conditions = $logic->conditions;

        /**
         * @todo Figure out how to embed date on the logic record
         */
        $dateInTenMins = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $dateNow = date('Y-m-d H:i:s');

        return $expressionLanguage->evaluate(
            $conditions,
            [
                'message' => $this->message,
                'creationDate' => $dateInTenMins,
            ]
        );
    }
}
