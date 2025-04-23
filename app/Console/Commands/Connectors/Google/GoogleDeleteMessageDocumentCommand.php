<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Google;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Google\Services\DiscoveryEngineDocumentService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class GoogleDeleteMessageDocumentCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:google-delete-all-message-document {app_id} {company_id} {message_type_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Send all messages to google recommendation as documents';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));

        $messageType = (int) $this->argument('message_type_id');

        $messageType = MessageType::getById($messageType, $app);
        $query = Message::fromApp($app)->notDeleted()->where('message_types_id', $messageType->getId())->orderBy('id', 'DESC');
        $cursor = $query->cursor();

        $totalMessages = $query->count();

        $messageRecommendation = new DiscoveryEngineDocumentService($app, $company);

        $this->output->progressStart($totalMessages);

        foreach ($cursor as $message) {
            $messageRecommendation->deleteDocument($message);
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

    }
}
