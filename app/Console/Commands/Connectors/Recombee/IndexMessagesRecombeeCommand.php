<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Recombee;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Services\RecombeeIndexService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class IndexMessagesRecombeeCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:recombee-index-messages {app_id} {message_type_id}';

    protected $description = 'Index messages to recombee';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $messageType = (int) $this->argument('message_type_id');

        $messageType = MessageType::getById($messageType, $app);

        $query = Message::fromApp($app)->where('message_types_id', $messageType->getId())->orderBy('id', 'desc');
        $cursor = $query->cursor();
        $totalMessages = $query->count();

        $this->output->progressStart($totalMessages);
        $messageIndex = new RecombeeIndexService($app);
        $messageIndex->createPromptMessageDatabase();

        foreach ($cursor as $message) {
            try {
                $result = $messageIndex->indexPromptMessage($message);

                $this->info('Message ID: '.$message->getId().' indexed with result: '.$result);
                $this->output->progressAdvance();
            } catch (Exception $e) {
                $this->error('Error indexing message ID: '.$message->getId().' with error: '.$e->getMessage());
            }
        }

        $this->output->progressFinish();

    }
}
