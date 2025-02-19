<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Google\Cloud\DiscoveryEngine\V1\Document;
use Exception;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Recombee\RecommApi\Client;
use Recombee\RecommApi\Requests\AddUser;
use Recombee\RecommApi\Requests\DeleteUser;
use Recombee\RecommApi\Requests\SetUserValues;
use Recombee\RecommApi\Requests\AddUserProperty;


use function Sentry\captureException;

class UploadFollowDataRecommendatorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-follow-engine:upload-follow-data {app_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upload follow data to the recommendation engine';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::find($this->argument('app_id'));
        $client = new Client(
            $app->get('recombee-database-id'),
            $app->get('recombee-secret-token'),
            ['region' => 'ca-east',
            'timeout' => 5000
            ]
        );

        $query = UsersAssociatedApps::fromApp($app)
            ->where('is_deleted', 0)
            ->where('companies_id', 0)
            ->where('user_active', 1)
            ->orderBy('id', 'DESC');

        $query->chunk(100, function ($users) use ($client, $app) {
            foreach ($users as $user) {

                //Get the tags the user is following
                
                $userLikedTagsArray = $this->getUserLikedTags($app, $user, 2626);

    
                print_r(array_values(array_unique($this->cleanTags($userLikedTagsArray))));
                die();

                // $client->send(new AddUser(2));
                // $client->send(new AddUserProperty('tags', 'set'));

                $user = UsersAssociatedApps::query()->where('users_id', 2)->first();
                // Add the tags to as user properties
                $client->send(new SetUserValues(
                    $user->users_id,
                    [
                        // 'firstname' => $user->firstname,
                        // 'lastname' => $user->lastname,
                        // 'email' => $user->email,
                        // 'displayname' => $user->displayname,
                        'liked_categories' => json_encode(array_values(array_unique($this->cleanTags($userLikedTagsArray))))
                    ],
                    ['cascadeCreate' => true]
                ));



                // // $response = $client->send(new DeleteUser(4527));

                // echo("User with id: $user->users_id added? $response");
                // echo("\n");

            }
        });
    }

    private function getUserLikedTags($app, $user, $companies_id): array
    {
        $interactionIdsArray = $this->getAllInteractions(['like'], $app);
        $userLikedTagsArray = [];

        //Get the tags the user is following
        $userInteraction = UsersInteractions::fromApp($app)
            ->where('users_id', 2)
            ->where("entity_namespace", Message::class)
            ->where('is_deleted', 0)
            ->where('interactions_id', $interactionIdsArray)
            ->get();

        // We need to get the liked messages and get the tags from them to know what the user is following and what it likes
        foreach ($userInteraction as $likeInteraction) {

            $userLikedTags = $likeInteraction->entity->tags()
                ->where('companies_id', $companies_id)
                ->pluck('name')->toArray() ?? [];
            foreach ($userLikedTags as $tagArray) {

                if ($tagArray == null) {
                    continue;
                }

                $userLikedTagsArray[] = $tagArray;
            }
        }

        return array_unique($userLikedTagsArray);
    }

    private function cleanTags($tagsArray) {
        return array_map(function($tag) {
            return strtolower(str_replace([' ', '_'], '-', trim($tag)));
        }, $tagsArray);
    }

    private function getAllInteractions( array $interactionsList, $app): array {
        return Interactions::fromApp($app)
            ->whereIn('name', $interactionsList)
            ->where('is_deleted', 0)
            ->pluck('id')
            ->toArray();
    }
    
}
