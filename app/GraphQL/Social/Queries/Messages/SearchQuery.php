<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Messages;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;

class SearchQuery
{
    /**
     * __invoke
     *
     * @return void
     */
    public function __invoke(
        mixed $root,
        array $request
    ) {
        $appsId = app(Apps::class)->id;
        $message = Message::search($request['text'])->where('apps_id', $appsId)->get();

        return $message;
    }
}
