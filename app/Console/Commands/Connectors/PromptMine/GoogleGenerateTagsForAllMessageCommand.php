<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Google\Actions\GenerateMessageTagAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\Tags\Models\Tag;

class GoogleGenerateTagsForAllMessageCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:prompt-google-generate-tags-message {app_id} {company_id} {message_type_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Generate tags for all messages in google recommendation';

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

        $query = Message::fromApp($app)->where('message_types_id', $messageType->getId());
        $cursor = $query->cursor();
        $totalMessages = $query->count();

        $this->output->progressStart($totalMessages);
        $featureTags = Tag::fromApp($app)->where('is_feature', 1)->get()->pluck('name')->toArray();
        $tagsToIgnore = ['text', 'image', 'openai', 'gemini', 'claude', 'xai', 'groq', 'flux', 'dalle3', 'deepseekai'];
        $allTags = Tag::fromApp($app)->notDeleted()->whereNotIn('slug', $tagsToIgnore)->get()->pluck('name')->toArray();

        foreach ($cursor as $message) {
            $generateMessageTagAction = new GenerateMessageTagAction($message);
            $messageTags = $generateMessageTagAction->execute(
                textLookupKey: 'ai_nugged.nugget',
                totalTags: 3,
                tags: $allTags
            );

            //also from the features
            if (! empty($featureTags)) {
                $messageTags = $generateMessageTagAction->execute(
                    textLookupKey: 'ai_nugged.nugget',
                    tags: $featureTags,
                    totalTags: 3
                );
            }
            $this->info('Message ID: ' . $message->getId() . ' Tags: ' . json_encode($messageTags->tags->pluck('name'), JSON_PRETTY_PRINT));
            //$this->newLine();
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        return;
    }
}
