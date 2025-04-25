<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Google\Service\Sheets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\UsersAssociatedApps;

class RedistributePromptsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:redistribute-prompts-google-sheet {--appId=78} {--messageType=588} {--nuggetMessageType=588} {--companyId=2626}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Redistribute prompts from a Google Sheet';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        // The Google Client setup looks correct, but we should check if the GOOGLE_AUTH_FILE exists
        if (! file_exists(getenv('GOOGLE_AUTH_FILE'))) {
            throw new \Exception('Google Auth file not found: ' . getenv('GOOGLE_AUTH_FILE'));
        }

        $client = new \Google\Client();
        $client->setApplicationName('Kanvas');
        $client->setAuthConfig(getenv('GOOGLE_AUTH_FILE'));
        $client->setScopes([Sheets::SPREADSHEETS_READONLY]);

        $service = new Sheets($client);
        $spreadsheetId = getenv('REDISTRIBUTE_PROMPTS_GOOGLE_SHEET_ID');
        $range = 'A:D';

        $appId = (int) $this->option('appId');
        $messageType = (int) $this->option('messageType');
        $nuggetMessageType = (int) $this->option('nuggetMessageType');
        $companyId = (int) $this->option('companyId');

        $spreadsheet = $service->spreadsheets->get($spreadsheetId);
        $sheets = $spreadsheet->getSheets();

        foreach ($sheets as $sheet) {
            $skipSheet = false;
            $sheetName = $sheet->getProperties()->getTitle();
            $range = $sheetName;
            $response = $service->spreadsheets_values->get($spreadsheetId, $range);
            $values = $response->getValues();

            $promptsCollection = [];

            if (! empty($values)) {
                array_shift($values);
                $headers = [
                    'prompt_id',
                    'user_id',
                    'category',
                    'displayname'
                ];

                foreach ($values as $row) {
                    $redistributeArray = [];
                    foreach ($headers as $index => $header) {
                        $value = $row[$index] ?? null;
                        $redistributeArray[$header] = $value;
                    }

                    $redistributeCollection[] = $redistributeArray;
                }
            } else {
                echo "No data found.";
            }

            if ($skipSheet) {
                echo "Skipping sheet $sheetName due to null or empty required values.\n";
                continue;
            }

            if (! empty($redistributeCollection)) {
                $this->redistributePrompts($redistributeCollection, $appId, $messageType, $nuggetMessageType, $companyId);
            }

            $redistributeCollection = [];
        }
    }

    private function redistributePrompts(array $redistributionInformation, int $appId, int $messageType, int $nuggetMessageType, int $companyId): void
    {
        foreach ($redistributionInformation as $redistribution) {
            $promptId = $redistribution['prompt_id'];
            $userId = $redistribution['user_id'];
            $category = $redistribution['category'];
            $displayname = $redistribution['displayname'];

            // Update parent information
            $prompt = Message::query()
                ->where('id', $promptId)
                ->where('apps_id', $appId)
                ->where('companies_id', $companyId)
                ->where('message_types_id', $messageType)
                ->where('is_deleted', 0)
                ->firstOrFail();

            if (! $prompt) {
                continue;
                Log::error("No message found for prompt $promptId");
            }

            $newPromptUser = UsersAssociatedApps::query()
                ->where('apps_id', $appId)
                ->where('companies_id', 0)
                ->where('displayname', $displayname)
                ->firstOrFail();

            $prompt->users_id = $newPromptUser->users_id;
            $prompt->saveOrFail();

            Log::info("Updated prompt $promptId to user $newPromptUser->users_id from user $userId");

            $newPromptUserId = $newPromptUser->users_id;

            // Update tags
            $tagId = $this->createTags($category, $newPromptUserId, $appId, $companyId);
            $this->assignTagsToMessage($prompt->getId(), $tagId, $newPromptUserId);

            Log::info("Assigned tag $tagId to prompt $promptId");

            // Update child information
            $childMessages = Message::query()
                ->where('parent_id', $promptId)
                ->where('apps_id', $appId)
                ->where('companies_id', $companyId)
                ->where('message_types_id', $nuggetMessageType)
                ->where('is_deleted', 0)
                ->get();

            if (! $childMessages) {
                continue;
                Log::error("No child messages found for prompt $promptId");
            }

            foreach ($childMessages as $childMessage) {
                $childMessage->users_id = $newPromptUserId;
                $childMessage->saveOrFail();

                Log::info("Updated child message $childMessage->id to user $newPromptUserId from user $userId");

                $this->assignTagsToMessage($childMessage->getId(), $tagId, $newPromptUserId);

                Log::info("Assigned tag $tagId to child message $childMessage->id");
            }
        }
    }


    private function createTags(string $tagName, int $userId, int $appId, int $companyId): int
    {
        return DB::connection('social')->table('tags')->insertGetId([
            'name' => $tagName,
            'apps_id' => $appId,
            'companies_id' => $companyId,
            'users_id' => $userId,
            'slug' => $this->slugify($tagName),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function assignTagsToMessage(int $entityId, int $tagId, int $userId): void
    {
        DB::connection('social')->table('tags_entities')->insert([
            'entity_id' => $entityId,
            'tags_id' => $tagId,
            'users_id' => $userId,
            'taggable_type' => "Kanvas\Social\Messages\Models\Message",
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);

        return strtolower($text) ?: 'n-a';
    }
}
