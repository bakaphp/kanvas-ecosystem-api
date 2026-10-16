<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Users;

use App\GraphQL\Social\Queries\Tags\TagsQueries;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Repositories\UsersRepository;
use Tests\TestCase;

/**
 * User tags sit on the (user, app, company) membership row, so the GraphQL surface has to honour
 * the same company boundary TagUserTool does.
 */
final class UserTagsTest extends TestCase
{
    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->admin = static::$cachedUser;
        $this->currentCompany = $this->admin->getCurrentCompany();
    }

    public function testUpdateUserTagsReplacesTheSet(): void
    {
        $teammate = $this->makeTeammate();
        $first = ['first-' . uniqid(), 'second-' . uniqid()];
        $second = ['third-' . uniqid()];

        $this->updateTags($teammate, $first);
        $this->assertEqualsCanonicalizing($first, $this->tagNames($this->membership($teammate)));

        $this->updateTags($teammate, $second);
        $this->assertSame($second, $this->tagNames($this->membership($teammate)));
    }

    public function testUserTagsDoNotLeakFromAnotherCompany(): void
    {
        $teammate = $this->makeTeammate();
        $otherCompany = Companies::factory()->create();
        $otherMembership = UsersAssociatedApps::create([
            'users_id' => $teammate->getId(),
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $otherCompany->getId(),
            'user_role' => 'users',
            'user_active' => 1,
        ]);
        $otherMembership->addTags(['private-' . uniqid()]);

        $this->assertSame([], $this->queryTags($teammate));

        $visible = 'visible-' . uniqid();
        $this->updateTags($teammate, [$visible]);

        $this->assertSame([$visible], $this->queryTags($teammate));
    }

    public function testUserOutsideTheCurrentCompanyHasNoTags(): void
    {
        $outsider = new RegisterUsersAction(RegisterInput::from([
            'email' => 'outsider-' . fake()->unique()->uuid() . '@tags.test',
            'password' => bin2hex(random_bytes(8)),
            'firstname' => 'No',
            'lastname' => 'Company',
        ]))->execute();

        $this->assertSame(0, new TagsQueries()->getUserTagsBuilder($outsider, [])->count());
    }

    /**
     * @param list<string> $tags
     */
    private function updateTags(Users $user, array $tags): void
    {
        $this->graphQL(/** @lang GraphQL */
            '
            mutation updateUser($id: ID!, $data: UpdateUserInput!) {
                updateUser(id: $id, data: $data) {
                    id
                }
            }',
            [
                'id' => $user->getId(),
                'data' => [
                    'tags' => array_map(fn (string $name): array => ['name' => $name], $tags),
                ],
            ]
        )->assertSuccessful()->assertJsonPath('data.updateUser.id', (string) $user->getId());
    }

    /**
     * @return list<string>
     */
    private function queryTags(Users $user): array
    {
        $response = $this->graphQL(/** @lang GraphQL */
            '
            query user($id: ID!) {
                user(id: $id) {
                    tags {
                        data {
                            name
                        }
                    }
                }
            }',
            ['id' => $user->getId()]
        )->assertSuccessful();

        return array_column($response->json('data.user.tags.data'), 'name');
    }

    private function membership(Users $user): UsersAssociatedApps
    {
        return UsersRepository::belongsToThisApp($user, $this->currentApp, $this->currentCompany);
    }

    /**
     * @return list<string>
     */
    private function tagNames(UsersAssociatedApps $membership): array
    {
        return $membership->tags()->pluck('name')->all();
    }

    private function makeTeammate(): Users
    {
        $user = new RegisterUsersAction(RegisterInput::from([
            'email' => 'teammate-' . fake()->unique()->uuid() . '@tags.test',
            'password' => bin2hex(random_bytes(8)),
            'firstname' => 'Tag',
            'lastname' => 'Teammate',
        ]))->execute();

        $branch = $this->currentCompany->branch ?? $this->currentCompany->branches()->first();
        $role = RolesRepository::getByNameFromCompany(RolesEnums::USER->value, $this->currentCompany, $this->currentApp);

        new AssignCompanyAction($user, $branch, $role, $this->currentApp)->execute();

        return $user;
    }
}
