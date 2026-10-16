<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\TagUserTool;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Repositories\UsersRepository;
use Tests\TestCase;

final class TagUserToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'social'];

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

    public function test_adds_and_removes_tags_on_a_teammate(): void
    {
        $teammate = $this->makeTeammate();
        $vip = 'vip-' . uniqid();
        $remote = 'remote-' . uniqid();

        $added = $this->tool()->__invoke(user_id: $teammate->getId(), tags: [$vip, " {$remote} ", $vip]);

        $this->assertTrue($added['success']);
        $this->assertEqualsCanonicalizing([$vip, $remote], $this->tagNames($this->membership($teammate)));

        $removed = $this->tool()->__invoke(user_id: $teammate->getId(), tags: [$vip], remove: true);

        $this->assertTrue($removed['success']);
        $this->assertSame([$remote], $this->tagNames($this->membership($teammate)));
    }

    public function test_tags_do_not_leak_to_the_same_user_in_another_company(): void
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

        $tag = 'private-' . uniqid();
        $this->tool()->__invoke(user_id: $teammate->getId(), tags: [$tag]);

        $this->assertSame([$tag], $this->tagNames($this->membership($teammate)));
        $this->assertSame([], $this->tagNames($otherMembership));
    }

    public function test_user_outside_the_company_is_not_found(): void
    {
        $stranger = Users::factory()->create();

        $result = $this->tool()->__invoke(user_id: $stranger->getId(), tags: ['vip']);

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['outcome']);
    }

    public function test_requires_a_tag(): void
    {
        $result = $this->tool()->__invoke(user_id: $this->makeTeammate()->getId(), tags: ['  ']);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function test_non_admin_is_denied(): void
    {
        $teammate = $this->makeTeammate();
        $roleless = Users::factory()->create();

        $result = $this->tool($roleless)->__invoke(user_id: $teammate->getId(), tags: ['vip']);

        $this->assertFalse($result['success']);
        $this->assertSame('denied', $result['outcome']);
    }

    private function tool(?Users $actor = null): TagUserTool
    {
        return new TagUserTool()->withContext($this->currentApp, $this->currentCompany, $actor ?? $this->admin);
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
