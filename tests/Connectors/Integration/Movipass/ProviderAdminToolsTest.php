<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Bouncer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Actions\ProviderAdmin\CreateProviderCompanyAction;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin\AssignProviderUserRoleTool;
use Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin\CreateProviderCompanyTool;
use Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin\FindProviderCompaniesTool;
use Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin\FindProviderUsersTool;
use Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin\InviteProviderUserTool;
use Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin\ListProviderRolesTool;
use Kanvas\Connectors\Movipass\Neuron\Tools\ProviderAdmin\RemoveProviderUserRoleTool;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedCompanies;
use Kanvas\Users\Models\UsersInvite;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class ProviderAdminToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'inventory', 'intelligence', 'social'];

    private Apps $kanvasApp;
    private Users $admin;
    private Users $operations;
    private Companies $company;
    private string $threadId;
    private string $turnId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set(AppSettingsEnums::DEFAULT_SIGNUP_ROLE->getValue(), RolesEnums::USER->value);
        $this->threadId = Str::uuid()->toString();
        $this->turnId = Str::uuid()->toString();
        $this->admin = auth()->user();
        $this->company = $this->admin->getCurrentCompany();

        $this->artisan('kanvas:movipass-setup-roles', ['app_id' => $this->kanvasApp->getId()])->assertSuccessful();

        $this->operations = $this->userWithRole(MovipassRolesEnum::OPERATIONS);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(AppSettingsEnums::DEFAULT_SIGNUP_ROLE->getValue(), RolesEnums::ADMIN->value);

        parent::tearDown();
    }

    public function testTheProviderAdminAbilityNoLongerExists(): void
    {
        $this->assertFalse(Bouncer::ability()->where('name', 'manage-provider-users')->exists());
    }

    public function testNonAdminsAreDeniedEvenWithAnOperationsRole(): void
    {
        $operator = $this->userWithRole(MovipassRolesEnum::OPERATOR);

        foreach ([$this->operations, $operator] as $human) {
            $result = $this->tool(FindProviderCompaniesTool::class, $human)->__invoke('anything');

            $this->assertSame('denied', $result['outcome']);
        }
    }

    public function testNonAdminsCannotWrite(): void
    {
        $company = $this->makeCompany('Gruas Denied ' . uniqid());
        $email = 'denied-' . uniqid() . '@kanvas.dev';

        $result = $this->tool(InviteProviderUserTool::class, $this->operations)->__invoke(
            $company->getId(),
            $email,
            MovipassRolesEnum::OPERATOR->value,
        );

        $this->assertSame('denied', $result['outcome']);
        $this->assertArrayNotHasKey('confirmation_token', $result);
        $this->assertFalse(UsersInvite::query()->where('email', $email)->exists());
    }

    public function testNoIdentifiedHumanIsDenied(): void
    {
        $result = $this->tool(FindProviderCompaniesTool::class, null)->__invoke('anything');

        $this->assertSame('denied', $result['outcome']);
    }

    public function testFindCompaniesReturnsCandidatesAndFlagsAmbiguity(): void
    {
        $suffix = uniqid();
        $this->makeCompany("Gruas Colon Norte {$suffix}");
        $this->makeCompany("Gruas Colon Sur {$suffix}");

        $result = $this->tool(FindProviderCompaniesTool::class, $this->admin)->__invoke("Gruas Colon {$suffix}");

        $this->assertSame(2, $result['count']);
        $this->assertTrue($result['ambiguous']);
        $this->assertStringContainsString('ask the person which one', $result['note']);
    }

    public function testFindCompaniesSingleMatchIsNotAmbiguous(): void
    {
        $name = 'Parqueo Unico ' . uniqid();
        $company = $this->makeCompany($name);

        $result = $this->tool(FindProviderCompaniesTool::class, $this->admin)->__invoke($name);

        $this->assertFalse($result['ambiguous']);
        $this->assertSame($company->getId(), $result['companies'][0]['id']);
    }

    public function testFindCompaniesReportsNothingFound(): void
    {
        $result = $this->tool(FindProviderCompaniesTool::class, $this->admin)->__invoke('zzz-no-such-company-' . uniqid());

        $this->assertSame('not_found', $result['outcome']);
    }

    public function testFindUsersListsCompaniesAndRoles(): void
    {
        $company = $this->makeCompany('Gruas Roles ' . uniqid());
        $target = $this->createUser();
        $this->assignThroughTools($target, $company, MovipassRolesEnum::TRUCK_DRIVER);

        $result = $this->tool(FindProviderUsersTool::class, $this->admin)->__invoke($target->email);

        $this->assertSame(1, $result['count']);
        $this->assertContains(MovipassRolesEnum::TRUCK_DRIVER->value, $result['users'][0]['roles']);
        $this->assertContains($company->getId(), array_column($result['users'][0]['companies'], 'id'));
    }

    public function testListRolesOffersOnlyMovipassRoles(): void
    {
        $roles = array_column($this->tool(ListProviderRolesTool::class, $this->admin)->__invoke()['roles'], 'role');

        $this->assertContains(MovipassRolesEnum::OPERATOR->value, $roles);
        $this->assertNotContains(RolesEnums::OWNER->value, $roles);
        $this->assertNotContains(RolesEnums::ADMIN->value, $roles);
    }

    public function testInviteIsPreviewedBeforeItIsSent(): void
    {
        Notification::fake();
        $company = $this->makeCompany('Gruas Invite ' . uniqid());
        $email = 'tecnico-' . uniqid() . '@kanvas.dev';

        $preview = $this->invite($company, $email, MovipassRolesEnum::OPERATOR);

        $this->assertSame('confirmation_required', $preview['status']);
        $this->assertFalse($preview['executed']);
        $this->assertNotEmpty($preview['confirmation_token']);
        $this->assertFalse(UsersInvite::query()->where('email', $email)->exists());

        $this->nextTurn();

        $done = $this->invite($company, $email, MovipassRolesEnum::OPERATOR, $preview['confirmation_token']);

        $this->assertTrue($done['executed']);
        $invite = UsersInvite::query()->where('email', $email)->firstOrFail();
        $this->assertSame($company->getId(), (int) $invite->companies_id);
        $this->assertSame(
            $this->roleId(MovipassRolesEnum::OPERATOR),
            (int) $invite->role_id
        );
    }

    public function testConfirmationTokenIsBoundToArgumentsAndSingleUse(): void
    {
        Notification::fake();
        $company = $this->makeCompany('Gruas Token ' . uniqid());
        $email = 'token-' . uniqid() . '@kanvas.dev';

        $preview = $this->invite($company, $email, MovipassRolesEnum::OPERATOR);

        $this->nextTurn();

        $otherRole = $this->invite($company, $email, MovipassRolesEnum::TRUCK_DRIVER, $preview['confirmation_token']);
        $this->assertSame('confirmation_required', $otherRole['status']);
        $this->assertFalse(UsersInvite::query()->where('email', $email)->exists());

        $fresh = $this->invite($company, $email, MovipassRolesEnum::OPERATOR);
        $this->nextTurn();
        $first = $this->invite($company, $email, MovipassRolesEnum::OPERATOR, $fresh['confirmation_token']);
        $this->assertTrue($first['executed']);

        $reuse = $this->invite($company, 'second-' . $email, MovipassRolesEnum::OPERATOR, $fresh['confirmation_token']);
        $this->assertSame('confirmation_required', $reuse['status']);
    }

    public function testConfirmationInTheSameTurnIsRejectedAndDoesNothing(): void
    {
        Notification::fake();
        $company = $this->makeCompany('Gruas Same Turn ' . uniqid());
        $email = 'same-' . uniqid() . '@kanvas.dev';

        $preview = $this->invite($company, $email, MovipassRolesEnum::OPERATOR);
        $sameTurn = $this->invite($company, $email, MovipassRolesEnum::OPERATOR, $preview['confirmation_token']);

        $this->assertSame('denied', $sameTurn['outcome']);
        $this->assertFalse($sameTurn['executed']);
        $this->assertFalse(UsersInvite::query()->where('email', $email)->exists());

        $this->nextTurn();
        $later = $this->invite($company, $email, MovipassRolesEnum::OPERATOR, $preview['confirmation_token']);

        $this->assertTrue($later['executed']);
    }

    public function testToolWithoutTurnContextFailsClosed(): void
    {
        $company = $this->makeCompany('Gruas No Turn ' . uniqid());
        $tool = new InviteProviderUserTool();
        $tool->withContext($this->kanvasApp, $this->company, $this->admin)->forRequestingUser($this->admin);

        $result = $tool->__invoke($company->getId(), 'noturn-' . uniqid() . '@kanvas.dev', MovipassRolesEnum::OPERATOR->value);

        $this->assertSame('denied', $result['outcome']);
        $this->assertArrayNotHasKey('confirmation_token', $result);
    }

    public function testConfirmationFromAnotherThreadIsRejected(): void
    {
        Notification::fake();
        $company = $this->makeCompany('Gruas Thread ' . uniqid());
        $email = 'thread-' . uniqid() . '@kanvas.dev';

        $preview = $this->invite($company, $email, MovipassRolesEnum::OPERATOR);
        $this->nextTurn();
        $this->threadId = Str::uuid()->toString();

        $result = $this->invite($company, $email, MovipassRolesEnum::OPERATOR, $preview['confirmation_token']);

        $this->assertSame('confirmation_required', $result['status']);
        $this->assertFalse(UsersInvite::query()->where('email', $email)->exists());
    }

    public function testSystemRolesAreNotAssignable(): void
    {
        $company = $this->makeCompany('Gruas System ' . uniqid());

        foreach ([RolesEnums::OWNER->value, RolesEnums::ADMIN->value, 'Admins', 'Owner'] as $role) {
            $result = $this->tool(InviteProviderUserTool::class, $this->admin)->__invoke(
                $company->getId(),
                'x-' . uniqid() . '@kanvas.dev',
                $role,
            );

            $this->assertSame('invalid_args', $result['outcome'], $role);
            $this->assertArrayNotHasKey('confirmation_token', $result);
        }
    }

    public function testInviteRefusesAnExistingAccount(): void
    {
        $company = $this->makeCompany('Gruas Existing ' . uniqid());
        $existing = $this->createUser();

        $result = $this->invite($company, $existing->email, MovipassRolesEnum::OPERATOR);

        $this->assertSame('invalid_args', $result['outcome']);
        $this->assertSame($existing->getId(), $result['user_id']);
    }

    public function testInviteRequiresARealEmail(): void
    {
        $company = $this->makeCompany('Gruas Email ' . uniqid());

        $result = $this->invite($company, 'tecnicoA', MovipassRolesEnum::OPERATOR);

        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function testAssignRoleUpdatesBouncerCompanyRowsAndLedger(): void
    {
        $company = $this->makeCompany('Gruas Assign ' . uniqid());
        $target = $this->createUser();

        $this->assertFalse($this->inAppScope(fn (): bool => $target->isA(MovipassRolesEnum::OPERATOR->value)));

        $this->assignThroughTools($target, $company, MovipassRolesEnum::OPERATOR);

        $this->assertTrue($this->inAppScope(fn (): bool => $target->isA(MovipassRolesEnum::OPERATOR->value)));
        $this->assertSame(
            $this->roleId(MovipassRolesEnum::OPERATOR),
            (int) UsersAssociatedCompanies::query()
                ->where('users_id', $target->getId())
                ->where('companies_id', $company->getId())
                ->value('user_role')
        );

        $audit = Event::query()
            ->where('event_type', 'movipass.provider_admin.role_assigned')
            ->where('actor_id', $this->admin->getId())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Movipass', $audit->source_domain);
        $this->assertSame($target->getId(), $audit->payload['target_user_id']);
    }

    public function testAssignSurvivesAForeignBouncerScopeAndRefreshesTheAppCache(): void
    {
        $company = $this->makeCompany('Gruas Scope ' . uniqid());
        $target = $this->createUser();
        $this->inAppScope(fn (): bool => $target->isA(MovipassRolesEnum::PARKING_OPERATOR->value));

        Bouncer::scope()->to('app_999999_company_0');
        $this->assignThroughTools($target, $company, MovipassRolesEnum::PARKING_OPERATOR);

        $this->assertTrue($this->inAppScope(fn (): bool => $target->isA(MovipassRolesEnum::PARKING_OPERATOR->value)));
    }

    public function testAssignRefusesAdministratorTargets(): void
    {
        $company = $this->makeCompany('Gruas Admin ' . uniqid());

        $result = $this->assign($this->admin, $company, MovipassRolesEnum::OPERATOR);

        $this->assertSame('denied', $result['outcome']);
        $this->assertArrayNotHasKey('confirmation_token', $result);
    }

    public function testAssignRefusesUnknownCompaniesAndUsers(): void
    {
        $target = $this->createUser();

        $noCompany = $this->tool(AssignProviderUserRoleTool::class, $this->admin)->__invoke(
            $target->getId(),
            999999999,
            MovipassRolesEnum::OPERATOR->value,
        );
        $noUser = $this->tool(AssignProviderUserRoleTool::class, $this->admin)->__invoke(
            999999999,
            $this->company->getId(),
            MovipassRolesEnum::OPERATOR->value,
        );

        $this->assertSame('not_found', $noCompany['outcome']);
        $this->assertSame('not_found', $noUser['outcome']);
    }

    public function testRemoveRoleNeedsConfirmationAndRetractsTheRole(): void
    {
        $company = $this->makeCompany('Gruas Remove ' . uniqid());
        $target = $this->createUser();
        $this->assignThroughTools($target, $company, MovipassRolesEnum::OPERATOR);

        $tool = $this->tool(RemoveProviderUserRoleTool::class, $this->admin);
        $preview = $tool->__invoke($target->getId(), $company->getId(), MovipassRolesEnum::OPERATOR->value);

        $this->assertSame('confirmation_required', $preview['status']);
        $this->assertTrue($this->inAppScope(fn (): bool => $target->isA(MovipassRolesEnum::OPERATOR->value)));

        $this->nextTurn();
        $tool = $this->tool(RemoveProviderUserRoleTool::class, $this->admin);

        $done = $tool->__invoke(
            $target->getId(),
            $company->getId(),
            MovipassRolesEnum::OPERATOR->value,
            $preview['confirmation_token'],
        );

        $this->assertTrue($done['executed']);
        $this->assertFalse($this->inAppScope(fn (): bool => $target->isA(MovipassRolesEnum::OPERATOR->value)));
    }

    public function testRemoveIsANoopWhenTheUserLacksTheRole(): void
    {
        $company = $this->makeCompany('Gruas Noop ' . uniqid());
        $target = $this->createUser();

        $result = $this->tool(RemoveProviderUserRoleTool::class, $this->admin)->__invoke(
            $target->getId(),
            $company->getId(),
            MovipassRolesEnum::OPERATOR->value,
        );

        $this->assertSame('noop', $result['outcome']);
    }

    public function testCreateCompanyNeedsConfirmationAndRejectsDuplicates(): void
    {
        $name = 'Proveedor Nuevo ' . uniqid();
        $tool = $this->tool(CreateProviderCompanyTool::class, $this->admin);

        $preview = $tool->__invoke($name);
        $this->assertSame('confirmation_required', $preview['status']);
        $this->assertFalse(Companies::query()->where('name', $name)->exists());

        $this->nextTurn();
        $tool = $this->tool(CreateProviderCompanyTool::class, $this->admin);
        $done = $tool->__invoke($name, null, null, $preview['confirmation_token']);
        $this->assertTrue($done['executed']);
        $this->assertTrue(Companies::query()->where('name', $name)->exists());

        $duplicate = $tool->__invoke(strtoupper($name));
        $this->assertSame('invalid_args', $duplicate['outcome']);
        $this->assertSame($done['company_id'], $duplicate['company_id']);
    }

    private function tool(string $class, ?Users $human): object
    {
        $tool = new $class();

        return $tool
            ->withContext($this->kanvasApp, $this->company, $this->admin)
            ->forRequestingUser($human)
            ->withTurn($this->threadId, $this->turnId);
    }

    private function nextTurn(): void
    {
        $this->turnId = Str::uuid()->toString();
    }

    private function makeCompany(string $name): Companies
    {
        return new CreateProviderCompanyAction($this->admin, $name, null, null, $this->kanvasApp)->execute();
    }

    private function userWithRole(MovipassRolesEnum $role): Users
    {
        $user = $this->createUser();

        $this->inAppScope(function () use ($user, $role): void {
            $user->assign($role->value);
            Bouncer::refreshFor($user);
        });

        return $user;
    }

    private function inAppScope(callable $callback): mixed
    {
        return Bouncer::scope()->onceTo(RolesEnums::getScope($this->kanvasApp), $callback);
    }

    private function roleId(MovipassRolesEnum $role): int
    {
        return (int) Role::query()
            ->where('name', $role->value)
            ->where('scope', RolesEnums::getScope($this->kanvasApp))
            ->value('id');
    }

    private function invite(
        Companies $company,
        string $email,
        MovipassRolesEnum $role,
        ?string $token = null,
    ): array {
        return $this->tool(InviteProviderUserTool::class, $this->admin)->__invoke(
            $company->getId(),
            $email,
            $role->value,
            'Tecnico',
            'Prueba',
            $token,
        );
    }

    private function assign(Users $target, Companies $company, MovipassRolesEnum $role, ?string $token = null): array
    {
        return $this->tool(AssignProviderUserRoleTool::class, $this->admin)->__invoke(
            $target->getId(),
            $company->getId(),
            $role->value,
            $token,
        );
    }

    private function assignThroughTools(Users $target, Companies $company, MovipassRolesEnum $role): void
    {
        $preview = $this->assign($target, $company, $role);
        $this->nextTurn();
        $done = $this->assign($target, $company, $role, $preview['confirmation_token']);

        $this->assertTrue($done['executed']);
    }
}
