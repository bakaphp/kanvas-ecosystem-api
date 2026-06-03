<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Notifications;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Actions\CreateNotificationTypeAction;
use Kanvas\Notifications\DataTransferObject\NotificationType;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Models\NotificationChannel;
use Kanvas\Notifications\Models\Notifications;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    /**
     * test_notification.
     *
     * @return void
     */
    public function testNotification()
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            {
                notifications(first: 10) {
                       data {
                            id,
                            types {
                                id
                            }
                        },
                        paginatorInfo {
                          currentPage
                          lastPage
                        }
                }
            }
            ');
        $this->assertArrayHasKey('data', $response);
    }

    /**
     * test_readAll.
     *
     * @return void
     */
    public function testReadAll()
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation {
                readAllNotifications
            }
            ');
        $this->assertArrayHasKey('data', $response);
        $response->assertJson([
            'data' => [
                'readAllNotifications' => true,
            ],
        ]);
    }

    /**
     * test_message_notification.
     *
     * @return void
     */
    public function testMessageNotificationToOneFollower()
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $createParentTemplate = new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => 'test-notification',
                'template' => '<html><body>Hello this is a test notification with {{ isset($dynamic) ? $dynamic : \'default value\' }} values</body></html>',
                ])
        );
        $template = $createParentTemplate->execute();

        $notificationType = (new CreateNotificationTypeAction(
            new NotificationType(
                app(Apps::class),
                $user,
                'test-notification',
                'test notification',
                $template
            )
        ))->execute();

        $notificationType->assignChannel(
            NotificationChannel::getById(NotificationChannelEnum::MAIL->value),
            $template
        );

        Notification::fake();

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation sendNotificationByMessage(
                    $metadata: NotificationMessageMetaDataInput!,
                    $message: Mixed!,
                ){
                    sendNotificationByMessage(
                        metadata: $metadata,
                        message: $message,
                    ){
                        sent,
                        message
                    }
                }
            ',
            [
                'metadata' => [
                    'notification_type_id' => $notificationType->getId(),
                    'distribution' => [
                        'type' => 'USERS',
                        'users_id' => [$user->getId()],
                    ],
                ],
                'message' => [
                    'title' => 'Example Title',
                    'is_public' => 1,
                    'is_published' => 1,
                ],
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $this->assertArrayHasKey('data', $response);
        $response->assertSee('sent');
        $response->assertSee('true');
        $response->assertSee('message');
    }

    /**
     * test_message_notification.
     *
     * @return void
     */
    public function testMessageNotificationToAllFollowers()
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $createParentTemplate = new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => 'test-notification',
                'template' => '<html><body>Hello this is a test notification with {{ isset($dynamic) ? $dynamic : \'default value\' }} values</body></html>',
                ])
        );
        $template = $createParentTemplate->execute();

        $notificationType = (new CreateNotificationTypeAction(
            new NotificationType(
                app(Apps::class),
                $user,
                'test-notification',
                'test notification',
                $template
            )
        ))->execute();

        $notificationType->assignChannel(
            NotificationChannel::getById(NotificationChannelEnum::MAIL->value),
            $template
        );

        Notification::fake();

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation sendNotificationByMessage(
                    $metadata: NotificationMessageMetaDataInput!,
                    $message: Mixed!,
                ){
                    sendNotificationByMessage(
                        metadata: $metadata,
                        message: $message,
                    ){
                        sent,
                        message
                    }
                }
            ',
            [
                'metadata' => [
                    'notification_type_id' => $notificationType->getId(),
                    'distribution' => [
                        'type' => 'FOLLOWERS',
                        'users_id' => [],
                    ],
                ],
                'message' => [
                    'title' => 'Example Title',
                    'is_public' => 1,
                    'is_published' => 1,
                ],
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $this->assertArrayHasKey('data', $response);
        $response->assertSee('sent');
        $response->assertSee('true');
        $response->assertSee('message');
    }

    /**
     * The notifications query is scoped by the user's current company via the
     * `fromCompany` scope — a notification belonging to another company must
     * never leak into the result set.
     */
    public function testNotificationScopedByCurrentCompany(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $otherCompany = Companies::factory()->create();

        $template = new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => 'test-notification-scope',
                'template' => '<html><body>scope test</body></html>',
            ])
        )->execute();

        $notificationType = new CreateNotificationTypeAction(
            new NotificationType(
                $app,
                $user,
                'test-notification-scope',
                'test notification scope',
                $template
            )
        )->execute();

        $notificationAttributes = [
            'users_id' => $user->getId(),
            'from_users_id' => $user->getId(),
            'apps_id' => $app->getId(),
            'system_modules_id' => 1,
            'notification_type_id' => $notificationType->getId(),
            'entity_id' => 1,
            'content' => 'scope test notification',
            'read' => 0,
        ];

        $currentCompanyNotification = Notifications::create(
            ['companies_id' => $company->getId()] + $notificationAttributes
        );
        $otherCompanyNotification = Notifications::create(
            ['companies_id' => $otherCompany->getId()] + $notificationAttributes
        );

        $response = $this->graphQL(/** @lang GraphQL */ '
            {
                notifications(first: 200) {
                    data {
                        id
                    }
                }
            }
            ');

        $response->assertSuccessful();

        $ids = array_column($response->json('data.notifications.data'), 'id');

        $this->assertContains((string) $currentCompanyNotification->getId(), $ids);
        $this->assertNotContains((string) $otherCompanyNotification->getId(), $ids);
    }

    /**
     * A super admin (app key context) can reach notifications across companies —
     * `fromCompany` opens up to `companies_id > 0` — and the `where` filter on
     * `companies_id` narrows the result to a single company. A normal user
     * context could never see another company's notifications.
     */
    public function testFilterNotificationByCompanyAsSuperAdmin(): void
    {
        $app = app(Apps::class);
        $appKey = $app->keys()->first();

        // Under an app key the middleware authenticates as the key's user, so the
        // `allNotifications` scope (users_id = auth user) matches against this user.
        $superAdminUser = $appKey->user()->firstOrFail();

        $companyA = Companies::factory()->create();
        $companyB = Companies::factory()->create();

        $template = new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => 'test-notification-super-admin-filter',
                'template' => '<html><body>super admin filter test</body></html>',
            ])
        )->execute();

        $notificationType = new CreateNotificationTypeAction(
            new NotificationType(
                $app,
                $superAdminUser,
                'test-notification-super-admin-filter',
                'test notification super admin filter',
                $template
            )
        )->execute();

        $notificationAttributes = [
            'users_id' => $superAdminUser->getId(),
            'from_users_id' => $superAdminUser->getId(),
            'apps_id' => $app->getId(),
            'system_modules_id' => 1,
            'notification_type_id' => $notificationType->getId(),
            'entity_id' => 1,
            'content' => 'super admin filter test notification',
            'read' => 0,
        ];

        $companyANotification = Notifications::create(
            ['companies_id' => $companyA->getId()] + $notificationAttributes
        );
        $companyBNotification = Notifications::create(
            ['companies_id' => $companyB->getId()] + $notificationAttributes
        );

        $response = $this->graphQL(/** @lang GraphQL */ '
            query notificationsByCompany($companyId: Mixed) {
                notifications(
                    first: 200
                    where: { column: COMPANIES_ID, operator: EQ, value: $companyId }
                ) {
                    data {
                        id
                        companies {
                            id
                        }
                    }
                }
            }
            ',
            [
                'companyId' => $companyA->getId(),
            ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $appKey->client_secret_id,
            ]
        );

        $response->assertSuccessful();

        $notifications = $response->json('data.notifications.data');
        $ids = array_column($notifications, 'id');

        $this->assertContains((string) $companyANotification->getId(), $ids);
        $this->assertNotContains((string) $companyBNotification->getId(), $ids);

        foreach ($notifications as $notification) {
            $this->assertEquals((string) $companyA->getId(), $notification['companies']['id']);
        }
    }

    /**
     * getEntityData eager-loads the relations passed in `fields.relations`. For a
     * notification whose entity is a Lead, requesting `people` loads the related
     * person so its firstname/lastname are available. A relation the entity does
     * not define is skipped silently — no RelationNotFoundException bubbles up.
     */
    public function testGetEntityDataLoadsLeadPeopleRelationAndSkipsMissingOnes(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $lead->people->firstname = 'Ada';
        $lead->people->lastname = 'Lovelace';
        $lead->people->saveOrFail();
        $lead->unsetRelation('people');

        $systemModule = new CreateInCurrentAppAction($app)->execute(Lead::class);
        $notificationType = $this->createNotificationType($app, $user, 'test-notification-lead-people');

        $notification = $this->createNotificationForEntity(
            $user,
            $app,
            $company->getId(),
            $systemModule->getId(),
            $notificationType->getId(),
            $lead->getId()
        );

        // people relation is loaded with the real firstname/lastname.
        $withPeople = $notification->getEntityData(['relations' => ['people']]);
        $this->assertInstanceOf(Lead::class, $withPeople);
        $this->assertTrue($withPeople->relationLoaded('people'));
        $this->assertSame('Ada', $withPeople->people->firstname);
        $this->assertSame('Lovelace', $withPeople->people->lastname);

        // Relation the entity does not define — must NOT throw, entity still returned.
        $withMissing = $notification->getEntityData(['relations' => ['thisRelationDoesNotExist']]);
        $this->assertInstanceOf(Lead::class, $withMissing);
        $this->assertFalse($withMissing->relationLoaded('thisRelationDoesNotExist'));

        // No `fields` at all still returns the entity unchanged.
        $this->assertInstanceOf(Lead::class, $notification->getEntityData());
    }

    /**
     * Same behavior end-to-end through the `notifications` GraphQL query: asking
     * for the Lead's `people` relation embeds firstname/lastname in the `entity`
     * JSON, while a relation the Lead does not define leaves the query successful
     * and simply omits that key.
     */
    public function testNotificationLeadPeopleEntityThroughGraphQL(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $lead->people->firstname = 'Grace';
        $lead->people->lastname = 'Hopper';
        $lead->people->saveOrFail();

        $systemModule = new CreateInCurrentAppAction($app)->execute(Lead::class);
        $notificationType = $this->createNotificationType($app, $user, 'test-notification-lead-people-graphql');

        $notification = $this->createNotificationForEntity(
            $user,
            $app,
            $company->getId(),
            $systemModule->getId(),
            $notificationType->getId(),
            $lead->getId()
        );

        $query = /** @lang GraphQL */ '
            query notificationEntity($id: Mixed, $fields: Mixed) {
                notifications(first: 1, where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        entity(fields: $fields)
                    }
                }
            }
        ';

        // people relation embedded in the entity JSON with firstname/lastname.
        $response = $this->graphQL($query, [
            'id' => $notification->getId(),
            'fields' => ['relations' => ['people']],
        ]);
        $response->assertSuccessful();

        $this->assertEquals(
            (string) $notification->getId(),
            $response->json('data.notifications.data.0.id')
        );

        $entity = $response->json('data.notifications.data.0.entity');
        $this->assertIsArray($entity);
        $this->assertArrayHasKey('people', $entity);
        $this->assertEquals('Grace', $entity['people']['firstname']);
        $this->assertEquals('Hopper', $entity['people']['lastname']);
    }

    /**
     * Requesting a relation the entity does not define must not break the query:
     * no GraphQL error is returned, the entity itself still comes back intact,
     * and the unknown relation key is simply absent from the JSON.
     */
    public function testNotificationEntityIgnoresUnknownRelationThroughGraphQL(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $systemModule = new CreateInCurrentAppAction($app)->execute(Lead::class);
        $notificationType = $this->createNotificationType($app, $user, 'test-notification-unknown-relation');

        $notification = $this->createNotificationForEntity(
            $user,
            $app,
            $company->getId(),
            $systemModule->getId(),
            $notificationType->getId(),
            $lead->getId()
        );

        $response = $this->graphQL(/** @lang GraphQL */ '
            query notificationEntity($id: Mixed, $fields: Mixed) {
                notifications(first: 1, where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        entity(fields: $fields)
                    }
                }
            }
        ', [
            'id' => $notification->getId(),
            'fields' => ['relations' => ['thisRelationDoesNotExist']],
        ]);

        $response->assertSuccessful();
        // No RelationNotFoundException surfaced to the client as a GraphQL error.
        $this->assertNull($response->json('errors'));

        $entity = $response->json('data.notifications.data.0.entity');
        $this->assertIsArray($entity);
        // The entity itself is still returned intact ...
        $this->assertEquals($lead->getId(), $entity['id']);
        // ... only the unknown relation is missing.
        $this->assertArrayNotHasKey('thisRelationDoesNotExist', $entity);
    }

    private function createNotificationType(Apps $app, Users $user, string $slug): NotificationTypes
    {
        $template = new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => $slug,
                'template' => '<html><body>' . $slug . '</body></html>',
            ])
        )->execute();

        return new CreateNotificationTypeAction(
            new NotificationType($app, $user, $slug, $slug, $template)
        )->execute();
    }

    private function createNotificationForEntity(
        Users $user,
        Apps $app,
        int $companyId,
        int $systemModuleId,
        int $notificationTypeId,
        int $entityId
    ): Notifications {
        return Notifications::create([
            'users_id' => $user->getId(),
            'from_users_id' => $user->getId(),
            'companies_id' => $companyId,
            'apps_id' => $app->getId(),
            'system_modules_id' => $systemModuleId,
            'notification_type_id' => $notificationTypeId,
            'entity_id' => $entityId,
            'content' => 'entity relations test',
            'read' => 0,
        ]);
    }
}
