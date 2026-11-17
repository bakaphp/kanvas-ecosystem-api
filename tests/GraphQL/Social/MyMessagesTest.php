<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Illuminate\Foundation\Testing\DatabaseTransactions;
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

    public function testOnlyReturnsRecordsWrittenByTheSignedInUser()
    {
        $app = app(Apps::class);
        $me = auth()->user();
        $recordType = MessageType::factory()->create();

        $coworker = Users::factory()->create();
        (new RegisterUsersAppAction($coworker, $app))->execute($coworker->password);

        $mine = Message::factory()->withMessageType($recordType)->create(
            $this->recordAttributes($app, $me->getId())
        );
        $coworkerRecord = Message::factory()->withMessageType($recordType)->create(
            $this->recordAttributes($app, $coworker->getId())
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
        $this->assertArrayNotHasKey('errors', (array) $response->json(), (string) json_encode($response->json()));

        $data = collect($response->json('data.myMessages.data'));
        $ids = $data->pluck('id')->all();

        $this->assertContains((string) $mine->getId(), $ids);
        $this->assertNotContains((string) $coworkerRecord->getId(), $ids);
        $this->assertCount(
            0,
            $data->reject(fn (array $row) => (string) $row['users_id'] === (string) $me->getId()),
            'myMessages returned a record written by someone else'
        );
    }

    public function testFiltersVisitPreparationQuestionsByMessageType()
    {
        $app = app(Apps::class);
        $me = auth()->user();
        $recordType = MessageType::factory()->create();
        $questionType = MessageType::factory()->create();

        $record = Message::factory()->withMessageType($recordType)->create(
            $this->recordAttributes($app, $me->getId())
        );
        $question = Message::factory()->withMessageType($questionType)->create(
            $this->recordAttributes($app, $me->getId())
        );

        $response = $this->graphQL(
            '
                query myMessages($verb: String!) {
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
                'verb' => $questionType->verb,
            ]
        );

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', (array) $response->json(), (string) json_encode($response->json()));

        $ids = collect($response->json('data.myMessages.data'))->pluck('id')->all();

        $this->assertContains((string) $question->getId(), $ids);
        $this->assertNotContains((string) $record->getId(), $ids);
    }

    private function recordAttributes(Apps $app, int $usersId): array
    {
        return [
            'apps_id' => $app->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'users_id' => $usersId,
            'is_public' => 1,
        ];
    }
}
