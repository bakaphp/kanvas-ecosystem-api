<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Exception;

class CheckNuggetGenerationCountAction
{
    public function __construct(
        private Message $message,
    ) {}

    public function execute(): bool
    {
        $freeGenerationCountCustomField = $this->message->user->getId() . '-nugget-free-generation-count';
        if ($this->message->get($freeGenerationCountCustomField) > $this->message->app->get('nugget-free-generation-limit')) {
            throw new Exception('You have reached the limit of nuggets you can generate for free');
        }

        ! $this->message->get($freeGenerationCountCustomField) ?
            $this->message->set($freeGenerationCountCustomField, 0) :
            $this->message->increment($freeGenerationCountCustomField);

        return true;
    }
}
