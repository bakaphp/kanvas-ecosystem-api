<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesComments\DataTransferObject;

use Kanvas\Apps\Models\Apps;

use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class MessageComment extends Data
{
    public function __construct(
        public Apps $apps,
        public Companies $companies,
        public Users $users,
        public Message $messages,
        public string $message,
        public int $parent_id = 0
    ) {
    }
}
