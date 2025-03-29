<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PromptMine;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use EchoLabs\Prism\Enums\Provider;
use EchoLabs\Prism\Prism;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;

class PromptAgentEngagerCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:prompt-agent-engager {app_id}}';
    protected $description = 'Redistribute prompts from a Google Sheet';
    protected ?string $url = null;
    protected ?string $appId = null;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->url = $app->get('graphql-url');
        $this->appId = $app->key;

        // Get the current hour and date
        $currentHour = (int) date('G');
        $currentDate = date('Y-m-d');

        $users = $app->get('prompt-engager-agents');

        if (! is_array($users) || empty($users)) {
            $this->error('No agents found for app ' . $app->name);

            return;
        }

        $totalPagesPerProfile = 3;

        // Get the current hour (0-23)
        $currentHour = (int) date('G');

        // Find the agent assigned to the current hour
        $currentAgent = null;
        foreach ($users as $user) {
            if (isset($user['activeHour']) && $user['activeHour'] === $currentHour) {
                $currentAgent = $user;

                break;
            }
        }

        // If no agent is assigned to this hour, exit
        if ($currentAgent === null) {
            $this->info('No agent assigned to hour ' . $currentHour . '. Exiting.');

            return;
        }

        // Create a unique Redis key for this agent and hour
        $redisKey = "agent_execution:{$currentAgent['email']}:{$currentDate}:{$currentHour}";

        // Check if this agent has already been executed in this hour
        if (Redis::exists($redisKey)) {
            $this->info('Agent ' . $currentAgent['email'] . ' has already executed for hour ' . $currentHour . ' today. Exiting.');

            return;
        }

        // Set the Redis key with an expiry of 24 hours (to clean up old keys)
        Redis::setex($redisKey, 86400, 'executed');

        $this->info('Starting agent for hour ' . $currentHour . ': ' . $currentAgent['email']);

        $agentDescription = $currentAgent['bio'];
        $token = $this->login($currentAgent['email'], $currentAgent['password']);

        $this->info('Agent logged in: ' . $currentAgent['email']);

        for ($page = 1; $page <= $totalPagesPerProfile; $page++) {
            $forYouFeed = $this->getForYouFeed($token, $page, 15);

            foreach ($forYouFeed['data'] as $message) {
                $content = 'Tittle :' . $message['message']['title'];
                $messageId = (int) $message['id'];

                $this->info('Analyzing content: ' . $content);

                $prompt = "Given the user's profile description:\n\n\"$agentDescription\"\n\n" .
                "Analyze the following content:\n\n\"$content\"\n\n" .
                "### Evaluation Criteria:\n" .
                "1. Assess whether the content aligns with the user's stated interests, preferences, and values.\n" .
                "2. Consider both explicitly mentioned interests and implicitly relevant topics.\n" .
                "3. Mark content as **relevant** if it:\n" .
                "   - Directly relates to the user's interests or topics.\n" .
                "   - Provides valuable insights on subjects the user is likely to care about.\n" .
                "   - Aligns with the user's apparent values or perspective.\n" .
                "4. Mark content as **irrelevant** if it:\n" .
                "   - Has no connection to the user's interests.\n" .
                "   - Contradicts the user's stated values or preferences.\n" .
                "   - Is too generic or unlikely to engage the user.\n" .
                "5. Always mark content as viewed, regardless of relevance.\n" .
                "6. If the user would likely want to explore full details, mark it as clicked.\n\n" .
                "### JSON Response Format:\n" .
                "Return ONLY a **true JSON object**, avoiding markdown:\n" .
                '{"view": 1, "click": 1, "like": 1} // If the content is relevant and the user would engage further' . "\n" .
                '{"view": 1, "click": 0, "like": 1} // If relevant but no deep engagement expected' . "\n" .
                '{"view": 1, "click": 0, "like": 0} // If not relevant' . "\n" ;

                $response = Prism::text()
                    ->using(Provider::Gemini, 'gemini-2.0-flash')
                    ->withPrompt($prompt)
                    ->generate();

                $responseText = str_replace(['```', 'json'], '', $response->text);

                if (! Str::isJson($responseText)) {
                    $this->error('Invalid response from Prism: ' . $responseText);

                    continue;
                }

                $engagement = json_decode($responseText, true);

                if ((int) $engagement['click'] === 1) {
                    $this->info('Engage viewing the message: ' . $messageId);
                    $this->getMessageById($token, $messageId);
                }

                if ((int) $engagement['like'] === 1) {
                    $this->info('Engage liking the message: ' . $messageId);
                    $this->likeMessage($token, $messageId);
                }

                sleep(5);
            }
        }

        $this->info('Agent ' . $currentAgent['email'] . ' completed work for hour ' . $currentHour);
    }

    protected function getClient(): Client
    {
        return new Client([
            'verify' => false,
            ]);
    }

    protected function getHeaders(array $additional = []): array
    {
        $appUuid = $this->appId;
        $branchUid = '';

        return array_merge([
            'X-Kanvas-App' => $appUuid,
            'X-Kanvas-Location' => $branchUid,
        ], $additional);
    }

    protected function login(string $email, string $password): string
    {
        $login = <<<GQL
mutation login(\$data: LoginInput!) {
  login(data: \$data) {
    id
    token
    refresh_token
    token_expires
    refresh_token_expires
    time
    timezone
  }
}
GQL;

        $getToken = $this->getClient()->post(
            $this->url,
            [
                'headers' => $this->getHeaders(),
                'json' => [
                    'query' => $login,
                    'variables' => [
                        'data' => [
                            'email' => $email,
                            'password' => $password,
                        ],
                    ],
                ],
            ]
        );

        $loginResponse = json_decode($getToken->getBody()->getContents(), true);

        return 'Bearer ' . $loginResponse['data']['login']['token'];
    }

    /**
 * Get the "For You" feed messages with pagination
 *
 * @param string $token Authentication token
 * @param int $page Page number for pagination
 * @param int $perPage Number of items per page
 * @param string $sortOrder Sort order (ASC or DESC)
 *
 * @return array Array of messages and pagination information
 */
    protected function getForYouFeed(
        string $token,
        int $page = 1,
        int $perPage = 15,
        string $sortOrder = 'DESC'
    ): array {
        // For different sort orders, we'll use different queries
        if ($sortOrder === 'ASC') {
            $query = <<<GQL
query ForYouMessages(\$first: Int!, \$page: Int!) {
    forYouMessages(
        first: \$first,
        page: \$page,
        orderBy: { column: CREATED_AT, order: ASC }
    ) {
        data {
            id
            message
            created_at
        }
        paginatorInfo {
            count
            currentPage
            firstItem
            hasMorePages
            lastItem
            lastPage
            perPage
            total
        }
    }
}
GQL;
        } else {
            // Default to DESC order
            $query = <<<GQL
query ForYouMessages(\$first: Int!, \$page: Int!) {
    forYouMessages(
        first: \$first,
        page: \$page
       
    ) {
        data {
            id
            message
            created_at
        }
        paginatorInfo {
            count
            currentPage
            firstItem
            hasMorePages
            lastItem
            lastPage
            perPage
            total
        }
    }
}
GQL;
        }

        try {
            $response = $this->getClient()->post(
                $this->url,
                [
                    'headers' => $this->getHeaders([
                        'Authorization' => $token,
                    ]),
                    'json' => [
                        'query' => $query,
                        'variables' => [
                            'first' => $perPage,
                            'page' => $page,
                        ],
                    ],
                ]
            );

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['errors'])) {
                $this->error('GraphQL Error: ' . json_encode($result['errors']));

                return [];
            }

            return $result['data']['forYouMessages'] ?? [];
        } catch (\Exception $e) {
            $this->error('Exception fetching ForYou messages: ' . $e->getMessage());

            return [];
        }
    }

    /**
    * Get a message by its ID
    *
    * @param string $token Authentication token
    * @param int $messageId The ID of the message to retrieve
    *
    * @return array|null The message data or null if not found
    */
    protected function getMessageById(string $token, int $messageId): ?array
    {
        $query = <<<GQL
query Message(\$messageId: Mixed!) {
    messages(first: 1, where: { column: ID, operator: EQ, value: \$messageId}) {
        data {
            id
            uuid
            message
            created_at
        }
    }
}
GQL;

        try {
            $response = $this->getClient()->post(
                $this->url,
                [
                    'headers' => $this->getHeaders([
                        'Authorization' => $token,
                    ]),
                    'json' => [
                        'query' => $query,
                        'variables' => [
                            'messageId' => $messageId,
                        ],
                    ],
                ]
            );

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['errors'])) {
                $this->error('GraphQL Error: ' . json_encode($result['errors']));

                return null;
            }

            // Return the first message in the data array or null if empty
            return $result['data']['messages']['data'][0] ?? null;
        } catch (\Exception $e) {
            $this->error('Exception fetching message: ' . $e->getMessage());

            return null;
        }
    }

    protected function likeMessage(string $token, $messageId): bool
    {
        $mutation = <<<GQL
mutation likeMessage(\$id: ID!) {
    likeMessage(id: \$id)
}
GQL;

        try {
            $response = $this->getClient()->post(
                $this->url,
                [
                    'headers' => $this->getHeaders([
                        'Authorization' => $token,
                    ]),
                    'json' => [
                        'query' => $mutation,
                        'variables' => [
                            'id' => $messageId,
                        ],
                    ],
                ]
            );

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['errors'])) {
                $this->error('GraphQL Error: ' . json_encode($result['errors']));

                return false;
            }

            // Check if the like action was successful
            return isset($result['data']['likeMessage']) && $result['data']['likeMessage'] === true;
        } catch (\Exception $e) {
            $this->error('Exception liking message: ' . $e->getMessage());

            return false;
        }
    }
}
