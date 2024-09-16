<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Users\Models\Users;

class ImportPromptsFromDocsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:import-prompts-from-docs';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Import prompts from googledocs';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $client = new \Google\Client();
        $client->setApplicationName("Kanvas");
        $client->setAuthConfig(getenv('GOOGLE_AUTH_FILE'));
        $client->setScopes([\Google\Service\Docs::DOCUMENTS_READONLY]);

        //extract content from docs
        $service = new \Google\Service\Docs($client);
        $document = $service->documents->get(getenv('GOOGLE_DOC_ID'));
        $body = $document->getBody();
        $rawContent = $this->parseBody($body);
        $processedContent = $this->processContent($rawContent);

        print_r($processedContent);
        // Now you can use $processedContent as needed
    }

    /**
     * Generate a slug from a string.
     *
     * @param string $text The string to generate a slug from.
     * @return string The generated slug.
     */
    public function slugify(string $text): string
    {
        // replace non letter or digits by -
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);

        // transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

        // remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);

        // trim
        $text = trim($text, '-');

        // remove duplicate -
        $text = preg_replace('~-+~', '-', $text);

        // lowercase
        $text = strtolower($text);

        if (empty($text)) {
            return 'n-a';
        }

        return $text;
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

        if (count($users) > 0) {
            // Select a random user from the fetched users
            return $users[array_rand($users->toArray())];
        } else {
            // Fallback to anonUser if no users are found
            return Users::find(-1);
        }
    }

    /**
     * Parse the content from the docs.
     *
     * @param \Google\Service\Docs\Document $document The document to parse.
     * @return array The parsed content.
     */
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
            // Add more conditions here for other element types if needed
        }
        return $content;
    }

    private function parseParagraph($paragraph)
    {
        $text = '';
        $elements = $paragraph->getElements();
        foreach ($elements as $element) {
            if ($element->getTextRun()) {
                $text .= $element->getTextRun()->getContent();
            }
        }
        return $text . "\n";
    }

    private function parseTable($table)
    {
        $text = '';
        $rows = $table->getTableRows();
        foreach ($rows as $row) {
            $cells = $row->getTableCells();
            foreach ($cells as $cell) {
                $text .= $this->parseBody($cell->getContent()) . "\t";
            }
            $text .= "\n";
        }
        return $text;
    }

    private function processContent($rawContent)
    {
        $result = [];
        $currentPrompt = [];
        $currentCategory = null;
        $keywords = ['Category:', 'Prompt Title:', 'Full Prompt:', 'Tags:'];

        // Split the content by the keywords
        $parts = preg_split('/(' . implode('|', array_map('preg_quote', $keywords)) . ')/', $rawContent, -1, PREG_SPLIT_DELIM_CAPTURE);

        $currentKey = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if (in_array($part, $keywords)) {
                $currentKey = rtrim($part, ':');
            } elseif (!empty($part) && !empty($currentKey)) {
                switch ($currentKey) {
                    case 'Category':
                        if ($currentCategory !== null && !empty($currentPrompt)) {
                            $result[$currentCategory][] = $currentPrompt;
                            $currentPrompt = [];
                        }
                        $currentCategory = $this->slugify($part);
                        if (!isset($result[$currentCategory])) {
                            $result[$currentCategory] = [];
                        }
                        break;
                    case 'Prompt Title':
                        if (!empty($currentPrompt)) {
                            $result[$currentCategory][] = $currentPrompt;
                        }
                        $currentPrompt = ['title' => $part];
                        break;
                    case 'Full Prompt':
                        $currentPrompt['full_prompt'] = $part;
                        break;
                    case 'Tags':
                        $currentPrompt['tags'] = array_map('trim', explode(',', $part));
                        break;
                }
            }
        }

        // Add the last prompt if it exists
        if ($currentCategory !== null && !empty($currentPrompt)) {
            $result[$currentCategory][] = $currentPrompt;
        }

        return $result;
    }
}
