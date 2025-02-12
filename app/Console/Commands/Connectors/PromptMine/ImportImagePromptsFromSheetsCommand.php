<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Users\Models\Users;
use Illuminate\Support\Facades\DB;
use Google\Service\Sheets;
use Illuminate\Support\Facades\Log;
use Kanvas\Users\Models\UsersAssociatedApps;
use PDO;

class ImportImagePromptsFromSheetsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:import-image-prompts-from-sheet {--appId=78} {--messageType=588} {--companyId=2626}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Import image prompts from Google Sheets document.';

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
        $spreadsheetId = getenv('IMAGE_PROMPTS_GOOGLE_SHEET_ID');
        $range = 'A:E';

        $appId = (int) $this->option('appId');
        $messageType = (int) $this->option('messageType');
        $companyId = (int) $this->option('companyId');

        $spreadsheet = $service->spreadsheets->get($spreadsheetId);
        $sheets = $spreadsheet->getSheets();

        foreach ($sheets as $sheet) {
            $sheetName = $sheet->getProperties()->getTitle();
            $range = $sheetName;
            $response = $service->spreadsheets_values->get($spreadsheetId, $range);
            $values = $response->getValues();

            $promptsCollection = [];

            if (! empty($values)) {
                array_shift($values);
                $headers = [
                    'title',
                    'prompt',
                    'username',
                    'user_id',
                    'category',
                ];

                foreach ($values as $row) {
                    $promptArray = [];
                    foreach ($headers as $index => $header) {
                        $value = $row[$index] ?? null;
                        $promptArray[$header] = $value;
                    }
                    $promptsCollection[] = $promptArray;
                }
            } else {
                echo "No data found.";
            }

            if (! empty($promptsCollection)) {
                $this->insertImagePrompts($promptsCollection, $appId, $messageType, $companyId);
            }
        }
    }


    public function insertImagePrompts(array $promptsCollection, int $appId, int $messageType, int $companyId)
    {
        foreach ($promptsCollection as $prompt) {

            $title = $prompt['title'];
            $promptText = $prompt['prompt'];
            $username = $prompt['username'];
            $userId = $prompt['user_id'];
            $category = $prompt['category'];

            $result = DB::connection('social')->table('messages')
                ->where('slug', $this->slugify($title))
                ->where('apps_id', $appId)
                ->first();

            if ($result) {
                echo ($title . ' message already exists with id: ' . $result->id . PHP_EOL);
                Log::info($title . ' message already exists with id: ' . $result->id);

                continue;
            }


            // Fetch the user from the database using username and user_id, we need to make sure the user exists
            $userFromAssocApp = UsersAssociatedApps::where('displayname', 'kaioken8432')
                ->where('users_id', 2)
                ->first();

            $userId = $userFromAssocApp->user->getId();

            //insert into db message
            $lastId = DB::connection('social')->table('messages')->insertGetId([
                'apps_id' => $appId,
                'uuid' => DB::raw('uuid()'),
                'companies_id' => $companyId,
                'users_id' => $userId,
                'message_types_id' => $messageType,
                'message' => json_encode([
                    'title' => $title,
                    'category' => $category,
                    'prompt' => $promptText,
                    'preview' => $promptText,
                ]),
                'slug' => $this->slugify($title),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update the `path` field with the last inserted ID
            DB::connection('social')->table('messages')
                ->where('id', $lastId)
                ->update(['path' => $lastId]);

            $categoryId = $this->fetchOrCreateCategory($category, $appId, $companyId, $userId);
            $this->assignTagToEntity($categoryId, $lastId, $userId);

            echo ($title . ' message inserted with id: ' . $lastId . PHP_EOL);
            Log::info($title . ' message inserted with id: ' . $lastId);
        }
    }

    /**
     * Generate a slug from a string.
     *
     * @param string $text The string to generate a slug from.
     * @return string The generated slug.
     */
    public function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);

        return strtolower($text) ?: 'n-a';
    }

    private function fetchOrCreateCategory(string $category, int $appId, int $companyId, int $userId): int
    {
        $category = trim(strtolower($category));
        $categoryResult = DB::connection('social')->table('tags')
            ->where('name', $category)
            ->where('apps_id', $appId)
            ->first();

        if ($categoryResult) {
            return $categoryResult->id;
        }

        return DB::connection('social')->table('tags')->insertGetId([
            'name' => $category,
            'apps_id' => $appId,
            'companies_id' => $companyId,
            'users_id' => $userId,
            'slug' => $this->slugify($category),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function assignTagToEntity(int $categoryId, int $entityId, int $userId): void
    {
        DB::connection('social')->table('tags_entities')->insert([
            'entity_id' => $entityId,
            'tags_id' => $categoryId,
            'users_id' => $userId,
            'taggable_type' => "Kanvas\Social\Messages\Models\Message",
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
