<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Messages;

use Kanvas\Social\Messages\Repositories\MessageRepository;
use Kanvas\SystemModules\Models\SystemModules;

class MessagesQueries
{
    public function getByAppModuleMessage(array $rootValue, array $req): ?Message
    {
        $systemModule = SystemModules::getById($req['systemModulesId']);
        if (! $message = MessageRepository::getByAppModuleMessage($systemModule->class_name, $req['entityId'])) {
            throw new Exception('Message not found');
        }


        return $message;
    }
}
