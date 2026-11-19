<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class MyMessagesTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social'];

    public function testOnlyReturnsMessagesWrittenByTheSignedInUser()
    {
        $app = app(Apps::class);
        $me = auth()->user();
        $messageType = MessageType::factory()->create();

        $coworker = Users::factory()->create();
        (new RegisterUsersAppAction($coworker, $app))->execute($coworker->password);

        $mine = Message::factory()->withMessageType($messageType)->create(
            $this->messageAttributes($app, $me->getId())
        );
        $coworkerMessage = Message::factory()->withMessageType($messageType)->create(
            $this->messageAttributes($app, $coworker->getId())
        );

        $response = $this->graphQL(
            '
                query myMessages {
                    myMessages {
                        data {
                            id
                            users_id
                        }
                    }
                }
            '
        );

        $response->assertSuccessful();
        $this->assertNoGraphQLErrors($response);

        $data = collect($response->json('data.myMessages.data'));
        $ids = $data->pluck('id')->all();

        $this->assertContains((string) $mine->getId(), $ids);
        $this->assertNotContains((string) $coworkerMessage->getId(), $ids);
        $this->assertCount(
            0,
            $data->reject(fn (array $row) => (string) $row['users_id'] === (string) $me->getId()),
            'myMessages returned a message written by someone else'
        );
    }

    public function testFiltersByMessageType()
    {
        $app = app(Apps::class);
        $me = auth()->user();
        $askedType = MessageType::factory()->create();
        $otherType = MessageType::factory()->create();

        $asked = Message::factory()->withMessageType($askedType)->create(
            $this->messageAttributes($app, $me->getId())
        );
        $other = Message::factory()->withMessageType($otherType)->create(
            $this->messageAttributes($app, $me->getId())
        );

        $response = $this->graphQL(
            '
                query myMessages($verb: Mixed) {
                    myMessages(hasType: {column: VERB, value: $verb}) {
                        data {
                            id
                            messageType {
                                verb
                            }
                        }
                    }
                }
            ',
            [
                'verb' => $askedType->verb,
            ]
        );

        $response->assertSuccessful();
        $this->assertNoGraphQLErrors($response);

        $ids = collect($response->json('data.myMessages.data'))->pluck('id')->all();

        $this->assertContains((string) $asked->getId(), $ids);
        $this->assertNotContains((string) $other->getId(), $ids);
    }

    private function assertNoGraphQLErrors(TestResponse $response): void
    {
        if ($response->json('errors') !== null) {
            $this->fail((string) json_encode($response->json('errors')));
        }
    }

    private function messageAttributes(Apps $app, int $usersId): array
    {
        return [
            'apps_id' => $app->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'users_id' => $usersId,
            'is_public' => 1,
        ];
    }
}
