<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Users\Models\Users;
use Illuminate\Support\Facades\DB;
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
        $client->setScopes([\Google\Service\Docs::DOCUMENTS_READONLY]);

        // Extract content from docs
        $service = new \Google\Service\Docs($client);
        $document = $service->documents->get(getenv('GOOGLE_DOC_ID'));
        $body = $document->getBody();
        $rawContent = $this->parseBody($body);
        $processedContent = $this->processContent($rawContent);

        // Retrieve command options
        $appId = $this->option('appId');
        $messageType = $this->option('messageType');
        $companyId = $this->option('companyId');
        $userId = $this->fetchRandomUser()->id;
        foreach ($processedContent as $category => $prompts) {
            echo $category . PHP_EOL;

            foreach ($prompts as $prompt) {
                // Check if the message already exists
                //if the msg exist with the same slug ignore
                $result = DB::connection('social')->table('messages')
                    ->where('slug', $this->slugify($prompt['title']))
                    ->where('apps_id', $appId)
                    ->first();

                if ($result) {
                    echo 'Message already exists' . PHP_EOL;

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
                        'preview' => $prompt['prompt'],
                        'prompt' => $prompt['prompt'],
                    ]),
                    'slug' => $this->slugify($prompt['title']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Update the `path` field with the last inserted ID
                DB::connection('social')->table('messages')
                    ->where('id', $lastId)
                    ->update(['path' => $lastId]);

                //find or create tags
                $tags = $prompt['tags'];
                $tags[] = $category;

                // Handle tags
                $tags = array_merge($prompt['tags'], [$category]);

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

                echo 'Message inserted' . PHP_EOL;
            }
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
        $usersDisplayNames = explode(',', getenv('PROMPT_ACCOUNT_DISPLAYNAMES'));

        if (empty($usersDisplayNames)) {
            $this->error('No display names provided in PROMPT_ACCOUNT_DISPLAYNAMES.');
            exit(1);
        }

        $users = Users::whereIn('displayname', $usersDisplayNames)
            ->where('is_deleted', 0)
            ->get();

        return count($users) > 0 ? $users[array_rand($users->toArray())] : Users::getByEmail(getenv('DEFAULT_PROMPT_ACCOUNT_EMAIL'));
    }

    private function parseBody($body)
    {
        $content = '';
        $elements = $body->getContent();
        foreach ($elements as $element) {
            if ($element->getParagraph()) {
                $content .= $this->parseParagraph($element->getParagraph());
            } elseif ($element->getTable()) {
                $content .= $this->parseTable($element->getTable());
            }
        }

        return $content;
    }

    private function parseParagraph($paragraph)
    {
        $paragraphStyle = $paragraph->getParagraphStyle();
        $namedStyleType = $paragraphStyle ? $paragraphStyle->getNamedStyleType() : null;

        if ($namedStyleType === 'TITLE') {
            return '';
        }

        $text = '';
        $elements = $paragraph->getElements();
        foreach ($elements as $element) {
            if ($element->getTextRun()) {
                $text .= $element->getTextRun()->getContent();
            }
        }

        return $text;
    }

    private function parseTable($table)
    {
        $text = '';
        $rows = $table->getTableRows();
        foreach ($rows as $row) {
            $cells = $row->getTableCells();
            foreach ($cells as $cell) {
                $text .= $this->parseBody($cell->getContent());
            }
        }

        return $text;
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
