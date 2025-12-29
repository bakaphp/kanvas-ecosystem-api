<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\Tags\Actions\CreateTagAction;
use Kanvas\Social\Tags\DataTransferObjects\Tag;

class PopulateSubcategoriesFeedCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:prompt-populate-subcategories-feed 
        {app_id : The application ID}
        {company_id : The company ID}
        {message_type_id : The message type ID}
        {category_slug : The category slug}
        {subcategory_slug : The subcategory slug}
        {message_id_list : Comma-separated message IDs}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Populate subcategories feed with a curated list of prompts';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $messageType = (int) $this->argument('message_type_id');
        $categorySlug = (string) $this->argument('category_slug');
        $subcategorySlug = (string) $this->argument('subcategory_slug');
        $messageIdList = explode(',', (string) $this->argument('message_id_list'));

        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));
        $messageType = MessageType::getById($messageType, $app);
        $user = $company->user;

        //Add the subcategory as a child of the category
        $categoryTag = (new CreateTagAction(
            new Tag(
                $app,
                $user,
                $company,
                $categorySlug
            )
        ))->execute();

        if (! $categoryTag->path) {
            $categoryTag->path = $categoryTag->id;
            $categoryTag->saveOrFail();
        }

        $subcategoryTag = (new CreateTagAction(
            new Tag(
                $app,
                $user,
                $company,
                $subcategorySlug
            )
        ))->execute();

        $subcategoryTag->parent_id = $categoryTag->id;
        $subcategoryTag->saveOrFail();

        //Lets tag all messages with the subcategory
        $messages = Message::fromApp($app)
            ->where('companies_id', $company->getId())
            ->where('message_types_id', $messageType->getId())
            ->whereIn('id', $messageIdList)
            ->get();

        foreach ($messages as $message) {
            $message->addTag($subcategorySlug, $app, $company->user, $company);
            echo("Tagged message ID {$message->id} with subcategory {$subcategorySlug} of category {$categorySlug}\n");
        }
    }
}
