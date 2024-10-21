<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Users\Models\Users;
use Illuminate\Support\Facades\DB;
use Google\Service\Sheets;
use PDO;

class ImportPromptsFromDocsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:import-prompts-from-docs {--appId=78} {--messageType=588} {--companyId=2626}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Import prompts from Google Docs';

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
        $range = 'A:E';

        // Fetch the entire sheet data
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $values = $response->getValues();

        $contentArray = [];
        $promptsCollection = [];
        // Process the sheet data as needed
        if (!empty($values)) {
            array_shift($values);
            $headers = [
                'title',
                'category',
                'prompt',
                'tags',
                'preview'
            ];
            
            foreach ($values as $row) {
                foreach ($row as $index => $value) {
                    $promptArray[$headers[$index]] = $value ?? null;
                }
                $promptsCollection [] = $promptArray;
            }
        } else {
            echo "No data found.";
        }

        // Retrieve command options
        $appId = $this->option('appId');
        $messageType = $this->option('messageType');
        $companyId = $this->option('companyId');
        $userId = $this->fetchRandomUser()->id;

        foreach ($promptsCollection as $prompt) {

            // Check if the message already exists
            //if the msg exist with the same slug ignore
            $result = DB::connection('social')->table('messages')
                ->where('slug', $this->slugify($prompt['title']))
                ->where('apps_id', $appId)
                ->first();

            if ($result) {
                echo($prompt['title'] . 'message already exists with id: ' . $result->id . PHP_EOL);

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
