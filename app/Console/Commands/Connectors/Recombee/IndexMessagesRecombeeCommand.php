<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Recombee;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Services\RecombeeIndexService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;

class IndexMessagesRecombeeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:recombee-index-messages {app_id} {message_type_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Index messages to recombee';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        // $this->overwriteAppService($app);

        $messageType = (int) $this->argument('message_type_id');

        $messageType = MessageType::getById($messageType, $app);

        $query = Message::fromApp($app)->where('message_types_id', $messageType->getId())->orderBy('id', 'desc');
        $cursor = $query->cursor();
        $totalMessages = $query->count();

        $this->output->progressStart($totalMessages);
        $messageIndex = new RecombeeIndexService(
            $app,
            $app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_DATABASE->value),
            $app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_API_KEY->value)
        );
        $messageIndex->createPromptMessageDatabase();

        foreach ($cursor as $message) {
            $result = $messageIndex->indexPromptMessage($message);

            $this->info('Message ID: ' . $message->getId() . ' indexed with result: ' . $result);
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        return;
    }
}
