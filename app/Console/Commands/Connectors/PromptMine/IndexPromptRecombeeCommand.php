<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Services\RecombeeIndexService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Throwable;

class IndexPromptRecombeeCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:prompt-index-recombee-message {app_id} {message_type_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Index prompt to recombee';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $messageType = (int) $this->argument('message_type_id');
        $messageType = MessageType::getById($messageType, $app);

        $query = Message::fromApp($app)->where('message_types_id', $messageType->getId())->orderBy('id', 'asc');
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
            } catch (Throwable $e) {
                $this->output->error($e->getMessage());
            }
        }

        $this->output->progressFinish();

    }
}
