<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

class SocialEngagementAgent extends BaseAgent
{
    protected ?string $authToken = null;
    protected ?string $graphqlUrl = null;
    protected array $sessionData = [];

    public function login(string $email, string $password): bool
    {
        try {
            $this->graphqlUrl = $this->app->get('graphql-url');

            $mutation = <<<GQL
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

            $response = $this->getClient()->post($this->graphqlUrl, [
                'headers' => $this->getHeaders(),
                'json' => [
                    'query' => $mutation,
                    'variables' => [
                        'data' => [
                            'email' => $email,
                            'password' => $password,
                        ],
                    ],
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['errors'])) {
                Log::error('Login failed', ['errors' => $result['errors']]);

                return false;
            }

            $this->authToken = 'Bearer ' . $result['data']['login']['token'];

            return true;
        } catch (Exception $e) {
            Log::error('Login failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getForYouFeed(int $page = 1, int $perPage = 15): array
    {
        if (! $this->authToken) {
            return [];
        }

        try {
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
            hasMorePages
            total
        }
    }
}
GQL;

            $response = $this->getClient()->post($this->graphqlUrl, [
                'headers' => $this->getHeaders([
                    'Authorization' => $this->authToken,
                ]),
                'json' => [
                    'query' => $query,
                    'variables' => [
                        'first' => $perPage,
                        'page' => $page,
                    ],
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['errors'])) {
                Log::error('GraphQL Error getting For You feed', ['errors' => $result['errors']]);

                return [];
            }

            return $result['data']['forYouMessages']['data'] ?? [];
        } catch (Exception $e) {
            Log::error('Failed to get For You feed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function getPublicMessages(int $page = 1, int $perPage = 30, int $messageTypeId = 572): array
    {
        if (! $this->authToken) {
            return [];
        }

        try {
            $query = <<<GQL
query Messages(\$first: Int!, \$page: Int!, \$messageTypeId: Mixed!) {
    messages(
        first: \$first,
        page: \$page,
        where: {
            AND: [
                {
                    column: MESSAGE_TYPES_ID,
                    operator: EQ,
                    value: \$messageTypeId
                },
                {
                    column: IS_PUBLIC,
                    operator: EQ,
                    value: 1
                }
            ]
        },
        orderBy: { column: CREATED_AT, order: DESC }
    ) {
        data {
            id
            message
            created_at
        }
        paginatorInfo {
            count
            currentPage
            hasMorePages
            total
        }
    }
}
GQL;

            $response = $this->getClient()->post($this->graphqlUrl, [
                'headers' => $this->getHeaders([
                    'Authorization' => $this->authToken,
                    'X-Kanvas-Key' => $this->app->keys()->first()->client_secret_id,
                ]),
                'json' => [
                    'query' => $query,
                    'variables' => [
                        'first' => $perPage,
                        'page' => $page,
                        'messageTypeId' => $messageTypeId,
                    ],
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['errors'])) {
                Log::error('GraphQL Error getting public messages', ['errors' => $result['errors']]);

                return [];
            }

            return $result['data']['messages']['data'] ?? [];
        } catch (Exception $e) {
            Log::error('Failed to get public messages', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function getMessageById(int $messageId): ?array
    {
        if (! $this->authToken) {
            return null;
        }

        try {
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

            $response = $this->getClient()->post($this->graphqlUrl, [
                'headers' => $this->getHeaders([
                    'Authorization' => $this->authToken,
                ]),
                'json' => [
                    'query' => $query,
                    'variables' => [
                        'messageId' => $messageId,
                    ],
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['errors'])) {
                Log::error('GraphQL Error getting message', ['errors' => $result['errors']]);

                return null;
            }

            return $result['data']['messages']['data'][0] ?? null;
        } catch (Exception $e) {
            Log::error('Failed to get message', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function likeMessage(int $messageId): bool
    {
        if (! $this->authToken) {
            return false;
        }

        try {
            $mutation = <<<GQL
mutation likeMessage(\$id: ID!) {
    likeMessage(id: \$id)
}
GQL;

            $response = $this->getClient()->post($this->graphqlUrl, [
                'headers' => $this->getHeaders([
                    'Authorization' => $this->authToken,
                ]),
                'json' => [
                    'query' => $mutation,
                    'variables' => [
                        'id' => $messageId,
                    ],
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['errors'])) {
                Log::error('GraphQL Error liking message', ['errors' => $result['errors']]);

                return false;
            }

            return $result['data']['likeMessage'] ?? false;
        } catch (Exception $e) {
            Log::error('Failed to like message', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function analyzeMessageEngagement(int $messageId, string $title, string $body, string $agentBio): array
    {
        /*  $prompt = "Analyze this social media message for engagement decisions.\n\n" .
             "### ACCOUNT PROFILE:\n" .
             "Bio: \"$agentBio\"\n\n" .
             "### MESSAGE TO ANALYZE:\n" .
             "Message ID: {$messageId}\n\n" .
             "Content: {$content}\n\n" .
             "### ANALYSIS PROCESS:\n" .
             "2. Get the message content details\n" .
             "3. Check if I've already interacted with this message\n" .
             "4. If already interacted, return skip action\n" .
             "5. If new message, analyze content against my profile and like history\n" .
             "6. Make selective engagement decisions based on demonstrated preferences\n\n" .
             "### RESPONSE FORMATS:\n" .
             "For new content: {\"action\": \"engage\", \"view\": 1, \"click\": 0, \"like\": 0}\n" .
             "For already seen: {\"action\": \"skip\", \"reason\": \"already_interacted\"}\n" .
             "For errors: {\"action\": \"error\", \"reason\": \"description\"}\n\n" .
             'Be highly selective with likes - only for content very similar to past preferences.'; */
        $prompt = "this is my bio just for a reminder: '{$this->entity->description}' , below is the content i need you to analyze \n\n title: '{$title}' \n content: '{$body}' \n message_id: {$messageId}";

        try {
            $message = new UserMessage($prompt);
            $response = $this->chat($message);

            $responseText = str_replace(['```', 'json'], '', $response->getContent());
            $responseText = trim($responseText);

            $result = json_decode($responseText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Invalid AI response for message analysis', [
                    'prompt' => $prompt,
                    'response' => $responseText,
                    'message_id' => $messageId,
                ]);

                return [
                    'action' => 'error',
                    'reason' => 'Invalid response format',
                ];
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Message engagement analysis failed', [
                'error' => $e->getMessage(),
                'message_id' => $messageId,
            ]);

            return [
                'action' => 'error',
                'reason' => 'Analysis failed: ' . $e->getMessage(),
            ];
        }
    }

    public function processEngagement(array $agentConfig): array
    {
        $email = $agentConfig['email'];
        $password = $agentConfig['password'];
        $agentBio = $agentConfig['bio'] ?? '';
        $totalPagesPerProfile = 1; //$agentConfig['pages_per_session'] ?? 3;

        // Login
        if (! $this->login($email, $password)) {
            return [
                'success' => false,
                'error' => 'Login failed',
            ];
        }

        $totalProcessed = 0;
        $totalSkipped = 0;
        $totalEngaged = 0;
        $totalViews = 0;
        $totalClicks = 0;
        $totalLikes = 0;
        $errors = 0;

        // Process multiple pages of content
        for ($page = 1; $page <= $totalPagesPerProfile; $page++) {
            // Get For You feed
            $forYouFeed = $this->getForYouFeed($page, 15);

            // Get public messages
            $publicMessages = $this->getPublicMessages($page, 30);

            // Combine all messages
            $allMessages = array_merge($forYouFeed, $publicMessages);

            foreach ($allMessages as $message) {
                if (! isset($message['id'])) {
                    continue;
                }

                if (! isset($message['message']['title'])) {
                    continue;
                }

                $messageId = (int) $message['id'];
                $title = $message['message']['title'];
                $body = $message['message']['nugget'] ?? $message['message']['prompt'] ?? $message['message']['content'] ?? '';

                $messageId = (int) $message['id'];

                $totalProcessed++;

                // Use AI to analyze this message with tools
                $analysis = $this->analyzeMessageEngagement($messageId, $title, $body, $agentBio);

                // Handle different response types

                //$totalEngaged++;

                // Perform engagements based on analysis
                if (($analysis['view'] ?? 0) === 1) {
                    $totalViews++;
                }

                if (($analysis['click'] ?? 0) === 1) {
                    $messageDetails = $this->getMessageById($messageId);
                    if ($messageDetails) {
                        $totalClicks++;
                        Log::info("Clicked on message {$messageId}");
                    }
                }

                if (($analysis['like'] ?? 0) === 1) {
                    $liked = $this->likeMessage($messageId);
                    if ($liked) {
                        $totalLikes++;
                        Log::info("Liked message {$messageId}");
                    }
                }

                // Add delay between messages to appear natural
                //sleep(rand(1, 4));
            }

            // Add delay between pages
            if ($page < $totalPagesPerProfile) {
                //sleep(rand(5, 10));
            }
        }

        return [
            'success' => true,
            'total_processed' => $totalProcessed,
            'total_skipped' => $totalSkipped,
            'total_engaged' => $totalEngaged,
            'views' => $totalViews,
            'clicks' => $totalClicks,
            'likes' => $totalLikes,
            'errors' => $errors,
            'pages_processed' => 1, // For testing
            'efficiency' => $totalProcessed > 0 ? round($totalSkipped / $totalProcessed * 100, 1) : 0,
        ];
    }

    protected function getClient(): Client
    {
        return new Client([
            'verify' => false,
        ]);
    }

    protected function getHeaders(array $additional = []): array
    {
        return array_merge([
            'X-Kanvas-App' => $this->app->key,
            'X-Kanvas-Location' => '',
            'Content-Type' => 'application/json',
        ], $additional);
    }

    #[Override]
    protected function tools(): array
    {
        return array_merge(parent::tools(), [
            Tool::make(
                'get_my_like_history',
                'Retrieve the like history and engagement patterns for this user account. This tool provides access to recently liked social media content, including message titles, engagement patterns, and preferences to maintain consistent behavior.',
            )->addProperty(
                new ToolProperty(
                    name: 'limit',
                    type: PropertyType::INTEGER,
                    description: 'Number of recent likes to retrieve (default: 20, max: 50)',
                    required: false
                )
            )->addProperty(
                new ToolProperty(
                    name: 'days_back',
                    type: PropertyType::INTEGER,
                    description: 'How many days back to look for likes (default: 30)',
                    required: false
                )
            )->setCallable(function (?int $limit = 20, ?int $days_back = 30) {
                return $this->getLikeHistoryData($limit, $days_back);
            }),

            Tool::make(
                'get_message_content',
                'Retrieve the full content and details of a social media message by its ID. This tool provides access to the message title, content, author, and other relevant details needed for engagement analysis.',
            )->addProperty(
                new ToolProperty(
                    name: 'message_id',
                    type: PropertyType::INTEGER,
                    description: 'The ID of the message to retrieve content for',
                    required: true
                )
            )->setCallable(function (int $message_id) {
                Log::info('Retrieving message content', [
                    'message_id' => $message_id,
                    'user_id' => $this->entity->id ?? 'unknown',
                ]);

                $messageDetails = Message::getById($message_id, $this->app);

                if (! $messageDetails) {
                    return [
                        'status' => 'error',
                        'message' => 'Message not found or could not be retrieved.',
                    ];
                }

                // Extract and format the message content
                $messageData = $messageDetails->message;

                return [
                    'status' => 'success',
                    'message_id' => $message_id,
                    'title' => $messageData['title'] ?? 'No title',
                    'content' => $messageData['nugget'] ?? $messageData['prompt'] ?? $messageData['content'] ?? '',
                    'created_at' => $messageDetails['created_at'] ?? '',
                    'uuid' => $messageDetails['uuid'] ?? '',
                    'full_content' => $messageData,
                ];
            }),

            Tool::make(
                'check_previous_interaction',
                'Check if this account has already interacted with a specific message to avoid duplicate engagements and maintain authentic behavior patterns.',
            )->addProperty(
                new ToolProperty(
                    name: 'message_id',
                    type: PropertyType::INTEGER,
                    description: 'The ID of the message to check for previous interactions',
                    required: true
                )
            )->setCallable(function (int $message_id) {
                try {
                    $userId = $this->entity->id;
                    $appId = $this->app->getId();

                    // Check for any existing interactions with this message
                    $existingInteractions = \Kanvas\Social\Interactions\Models\UsersInteractions::where('users_id', $userId)
                        ->where('apps_id', $appId)
                        ->where('entity_id', $message_id)
                        ->where('is_deleted', 0)
                        ->with('interaction')
                        ->get();

                    if ($existingInteractions->isEmpty()) {
                        return [
                            'status' => 'success',
                            'has_interacted' => false,
                            'message' => 'No previous interactions found',
                        ];
                    }

                    $interactions = [];
                    foreach ($existingInteractions as $interaction) {
                        $interactions[] = [
                            'type' => $interaction->interaction->name ?? 'unknown',
                            'created_at' => $interaction->created_at->format('Y-m-d H:i:s'),
                            'notes' => $interaction->notes ?? '',
                        ];
                    }

                    return [
                        'status' => 'success',
                        'has_interacted' => true,
                        'interactions' => $interactions,
                        'message' => 'Found ' . count($interactions) . ' previous interaction(s)',
                    ];
                } catch (Exception $e) {
                    Log::error('Failed to check previous interactions', [
                        'message_id' => $message_id,
                        'error' => $e->getMessage(),
                    ]);

                    return [
                        'status' => 'error',
                        'message' => 'Could not check previous interactions: ' . $e->getMessage(),
                        'has_interacted' => false,
                    ];
                }
            }),
        ]);
    }

    protected function getLikeHistoryData(?int $limit = 20, ?int $days_back = 30): array
    {
        // Add debug logging
        Log::info('getLikeHistoryData called', [
            'limit' => $limit,
            'days_back' => $days_back,
            'user_id' => $this->entity->id ?? 'unknown',
        ]);

        try {
            // Enforce limits
            $limit = min(max(1, $limit ?? 20), 50);
            $days_back = min(max(1, $days_back ?? 30), 90);

            // Get the current user ID from the entity (Person)
            if (! $this->entity || ! $this->entity->id) {
                return [
                    'status' => 'error',
                    'message' => 'No user entity found',
                    'recent_likes' => [],
                    'total_likes_found' => 0,
                ];
            }

            $userId = $this->entity->id;
            $appId = $this->app->getId();

            Log::info('Querying likes for user', [
                'user_id' => $userId,
                'app_id' => $appId,
            ]);

            // Simple query first - just get the interactions
            $likesQuery = UsersInteractions::where('users_id', $userId)
                ->where('apps_id', $appId)
                ->where('created_at', '>=', now()->subDays($days_back))
                ->where('is_deleted', 0)
                ->orderBy('created_at', 'desc')
                ->limit($limit);

            // Check if we can find the interaction by name/slug
            $likesQuery->whereHas('interaction', function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like')
                      ->orWhere('title', 'like');
                });
            });

            $likes = $likesQuery->get();

            Log::info('Found likes', ['count' => $likes->count()]);

            $likeHistory = [];
            foreach ($likes as $like) {
                try {
                    // Try to get the entity data (the message that was liked)
                    $entityData = $like->entityData();

                    $title = 'Unknown Content';

                    if ($entityData && $entityData instanceof Message) {
                        $messageData = $entityData->message;

                        $title = $messageData['title'] ?? $messageData['nugget'] ?? $messageData['prompt'] ?? $entityData->slug ?? 'No title';
                    }

                    $likeHistory[] = [
                        'message_id' => $like->entity_id,
                        'title' => $title,
                        'liked_at' => $like->created_at->format('Y-m-d H:i:s'),
                        'days_ago' => $like->created_at->diffInDays(now()),
                        'entity_namespace' => $like->entity_namespace ?? 'Unknown',
                    ];
                } catch (Exception $e) {
                    Log::warning('Could not process like', [
                        'like_id' => $like->id,
                        'error' => $e->getMessage(),
                    ]);

                    // Still add it with basic info
                    $likeHistory[] = [
                        'message_id' => $like->entity_id,
                        'title' => 'Content (could not load details)',
                        'liked_at' => $like->created_at->format('Y-m-d H:i:s'),
                        'days_ago' => $like->created_at->diffInDays(now()),
                        'entity_namespace' => $like->entity_namespace ?? 'Unknown',
                    ];
                }
            }

            // Simple patterns
            $patterns = [];
            if (count($likeHistory) > 0) {
                $patterns[] = 'Found ' . count($likeHistory) . ' recent likes';

                $recentLikes = array_filter($likeHistory, fn ($like) => $like['days_ago'] <= 7);
                if (count($recentLikes) > 0) {
                    $patterns[] = 'Liked ' . count($recentLikes) . ' items in the last week';
                }
            }

            $result = [
                'status' => 'success',
                'total_likes_found' => count($likeHistory),
                'recent_likes' => $likeHistory,
                'patterns' => $patterns,
                'account_bio' => 'i will like all content that i see',
                'user_bio' => 'i will like all content that i see',
                'user_description' => 'i will like all content that i see',
                'summary' => count($likeHistory) > 0 ?
                    'User has liked ' . count($likeHistory) . ' posts recently' :
                    'No recent likes found',
            ];

            Log::info('Like history retrieved successfully', $result);
            Log::info('Returning like history result', ['total_found' => count($likeHistory)]);

            return $result;
        } catch (Exception $e) {
            Log::error('Failed to get user like history', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $this->entity->id ?? 'unknown',
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to retrieve like history: ' . $e->getMessage(),
                'recent_likes' => [],
                'patterns' => [],
                'total_likes_found' => 0,
            ];
        }
    }
}
