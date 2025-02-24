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
use PDO;

class ImportPromptsFromSheetsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:import-prompts-from-sheet {--appId=78} {--messageType=588} {--nuggetMessageType=588} {--companyId=2626}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Import prompts from Google Sheets document.';

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
        $spreadsheetId = getenv('GOOGLE_SHEET_ID');
        $range = 'A:F';

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
                    'title',
                    'category',
                    'prompt',
                    'tags',
                    'preview',
                    'nugget'
                ];

                foreach ($values as $row) {
                    $promptArray = [];
                    foreach ($headers as $index => $header) {
                        $value = $row[$index] ?? null;
                        if ($header !== 'preview' && (is_null($value) || empty($value))) {
                            echo "Null or Empty value for $header on sheet $sheetName.\n";
                            $skipSheet = true;
                            break 2;
                        }
                        $promptArray[$header] = $value;
                    }
                    $promptsCollection[] = $promptArray;
                }
            } else {
                echo "No data found.";
            }

            if ($skipSheet) {
                echo "Skipping sheet $sheetName due to null or empty required values.\n";
                continue;
            }

            if (! empty($promptsCollection)) {
                $this->insertPrompts($promptsCollection, $appId, $messageType, $nuggetMessageType, $companyId);
            }

            $promptsCollection = [];
        }
    }


    public function insertPrompts(array $promptsCollection, int $appId, int $messageType, int $nuggetMessageType, int $companyId)
    {
        foreach ($promptsCollection as $prompt) {
            $userId = $this->fetchRandomUser()->id;
            $result = DB::connection('social')->table('messages')
                ->where('slug', $this->slugify($prompt['title']))
                ->where('apps_id', $appId)
                ->first();

            if ($result) {
                echo($prompt['title'] . 'message already exists with id: ' . $result->id . PHP_EOL);
                Log::info($prompt['title'] . 'message already exists with id: ' . $result->id);

                continue;
            }

            //insert into db message
            $lastId = DB::connection('social')->table('messages')->insertGetId([
                'apps_id' => $appId,
                'uuid' => DB::raw('uuid()'),
                'companies_id' => $companyId,
                'users_id' => $userId,
                'message_types_id' => $messageType,
                'message' => json_encode([
                    'title' => $prompt['title'],
                    'category' => $prompt['category'],
                    'prompt' => $prompt['prompt'],
                    'preview' => $prompt['preview'] ?? $prompt['prompt'],
                ]),
                'slug' => $this->slugify($prompt['title']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update the `path` field with the last inserted ID
            DB::connection('social')->table('messages')
                ->where('id', $lastId)
                ->update(['path' => $lastId]);

            //Create a child message for the nugget(prompt results) add the prompt preview as the message

            $nuggestId = DB::connection('social')->table('messages')->insertGetId([
                'apps_id' => $appId,
                'uuid' => DB::raw('uuid()'),
                'companies_id' => $companyId,
                'users_id' => $userId,
                'message_types_id' => $nuggetMessageType,
                'message' => json_encode([
                    'title' => $prompt['title'],
                    'ai_model' => [
                        'key' => 'openai',
                        'value' => 'chatgpt-4o-latest',
                        'name' => 'OpenAI - ChatGPT-4o',
                        'payment' => [
                            'price' => 0,
                            'is_locked' => false,
                            'free_regeneration' => false
                        ]
                    ],
                    "type" => "text-format",
                    "nugget" => $prompt['nugget']
                ]),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::connection('social')->table('messages')
                ->where('id', $nuggestId)
                ->update(['path' => $lastId . "." . $nuggestId]);

            // Handle tags
            $tags = array_merge(explode(',', $prompt['tags']), [$prompt['category']]);

            foreach ($tags as $tag) {
                $tag = trim(strtolower($tag));
                $tagResult = DB::connection('social')->table('tags')
                    ->where('name', $tag)
                    ->where('apps_id', $appId)
                    ->first();

                if ($tagResult) {
                    $tagId = $tagResult->id;
                } else {
                    $tagId = DB::connection('social')->table('tags')->insertGetId([
                        'name' => $tag,
                        'apps_id' => $appId,
                        'companies_id' => $companyId,
                        'users_id' => $userId,
                        'slug' => $this->slugify($tag),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                DB::connection('social')->table('tags_entities')->insert([
                    'entity_id' => $lastId,
                    'tags_id' => $tagId,
                    'users_id' => $userId,
                    'taggable_type' => "Kanvas\Social\Messages\Models\Message",
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            echo($prompt['title'] . ' message inserted with id: ' . $lastId . PHP_EOL);
            Log::info($prompt['title'] . ' message inserted with id: ' . $lastId);
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

    /**
     * Fetch a random user from the database.
     *
     * @return Users The random user.
     */
    private function fetchRandomUser(): Users
    {
        $usersEmails = explode(',', getenv('PROMPT_ACCOUNT_DISPLAYNAMES'));

        if (empty($usersEmails)) {
            $this->error('No display names provided in PROMPT_ACCOUNT_DISPLAYNAMES.');
            exit(1);
        }

        $users = Users::whereIn('email', $usersEmails)
            ->where('is_deleted', 0)
            ->get();

        return $users[array_rand($users->toArray())];
    }



    private function processContent($rawContent)
    {
        $result = [];
        $currentPrompt = [];
        $currentCategory = null;
        $keywords = ['Category:', 'Prompt Title:', 'Full Prompt:', 'Tags:'];

        $rawContent = preg_replace('/\s+/', ' ', $rawContent);
        $parts = preg_split('/(' . implode('|', array_map('preg_quote', $keywords)) . ')/', $rawContent, -1, PREG_SPLIT_DELIM_CAPTURE);

        $currentKey = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if (in_array($part, $keywords)) {
                $currentKey = rtrim($part, ':');
            } elseif (! empty($part) && ! empty($currentKey)) {
                switch ($currentKey) {
                    case 'Category':
                        if ($currentCategory !== null && ! empty($currentPrompt)) {
                            $result[$currentCategory][] = $currentPrompt;
                            $currentPrompt = [];
                        }
                        $currentCategory = $this->slugify($part);
                        if (! isset($result[$currentCategory])) {
                            $result[$currentCategory] = [];
                        }

                        break;
                    case 'Prompt Title':
                        if (! empty($currentPrompt)) {
                            $result[$currentCategory][] = $currentPrompt;
                        }
                        $currentPrompt = ['title' => $part];

                        break;
                    case 'Full Prompt':
                        $currentPrompt['prompt'] = $part;

                        break;
                    case 'Tags':
                        $currentPrompt['tags'] = array_map('trim', explode(',', $part));

                        break;
                }
            }
        }

        if ($currentCategory !== null && ! empty($currentPrompt)) {
            $result[$currentCategory][] = $currentPrompt;
        }

        return $result;
    }
}
