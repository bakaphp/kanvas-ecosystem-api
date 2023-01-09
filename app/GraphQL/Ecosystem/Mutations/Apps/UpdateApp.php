<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Actions\UpdateAppsAction;

final class UpdateApp
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
    {
        // TODO implement the resolver\
        $dto = AppInput::from($request['input']);
        $action = new UpdateAppsAction($dto, auth()->user());
        return $action->execute($request['id']);
    }
}
