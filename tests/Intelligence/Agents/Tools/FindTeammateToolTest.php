<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\FindTeammateTool;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * "Message Jenn" has to resolve to someone the send and snooze tools accept: a user account of this
 * company. A person outside the company, however well their name matches, is nobody to the agent.
 *
 * No transaction wrapper: the user row and its company association are written on different
 * connections, and the search joins them on one — inside per-connection transactions the join cannot
 * see the association. Emails are unique per run, so the rows left behind collide with nothing.
 */
class FindTeammateToolTest extends TestCase
{
    public function test_a_first_name_finds_the_teammate_with_the_email_the_send_tools_need(): void
    {
        $first = $this->uniqueName('Jennalyn');
        $jenn = $this->teammate($first, 'Ortega');

        $result = $this->tool()(query: $first);

        $this->assertSame(1, $result['count']);
        $this->assertSame($jenn->email, $result['teammates'][0]['email']);
        $this->assertSame($first . ' Ortega', $result['teammates'][0]['name']);
        $this->assertStringContainsString('send_email_to_user', $result['note']);
    }

    public function test_phone_numbers_come_back_when_the_teammate_has_them(): void
    {
        $first = $this->uniqueName('Jennalyn');
        $jenn = $this->teammate($first, 'Ortega');
        $jenn->cell_phone_number = '+18095551234';
        $jenn->phone_number = '';
        $jenn->saveOrFail();

        $teammate = $this->tool()(query: $first)['teammates'][0];

        $this->assertSame('+18095551234', $teammate['cell_phone']);
        $this->assertArrayNotHasKey('phone', $teammate);
    }

    public function test_an_email_or_a_handle_finds_them_too(): void
    {
        $jenn = $this->teammate('Jennalyn', 'Ortega');

        $this->assertSame($jenn->getId(), $this->tool()(query: $jenn->email)['teammates'][0]['user_id']);
        $this->assertSame($jenn->getId(), $this->tool()(query: '@' . $jenn->email)['teammates'][0]['user_id']);
    }

    public function test_several_matches_ask_which_one(): void
    {
        $first = $this->uniqueName('Jennalyn');
        $this->teammate($first, 'Ortega');
        $this->teammate($first, 'Price');

        $result = $this->tool()(query: $first);

        $this->assertSame(2, $result['count']);
        $this->assertStringContainsString('Ask which one', $result['note']);
    }

    public function test_a_user_outside_the_company_is_nobody(): void
    {
        $first = $this->uniqueName('Zebulon');
        $outsider = Users::factory()->create(['firstname' => $first, 'lastname' => 'Quartermaine']);

        $result = $this->tool()(query: $first);

        $this->assertSame(0, $result['count']);
        $this->assertStringContainsString('could not find them', $result['note']);
        $this->assertNotNull($outsider->getId());
    }

    public function test_an_empty_query_is_refused(): void
    {
        $this->assertSame('error', $this->tool()(query: '  ')['status']);
    }

    private function tool(): FindTeammateTool
    {
        return new FindTeammateTool()->withContext(
            app(Apps::class),
            static::$cachedUser->getCurrentCompany(),
            static::$cachedUser,
        );
    }

    private function uniqueName(string $base): string
    {
        return $base . ucfirst(substr(uniqid(), -5));
    }

    private function teammate(string $firstname, string $lastname): Users
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $user = new RegisterUsersAction(RegisterInput::from([
            'email' => strtolower($firstname . '.' . $lastname) . '-' . uniqid() . '@example.test',
            'password' => bin2hex(random_bytes(8)),
            'firstname' => $firstname,
            'lastname' => $lastname,
        ]))->execute();

        $branch = $company->branch ?? $company->branches()->first();
        $role = RolesRepository::getByNameFromCompany(RolesEnums::USER->value, $company, $app);
        new AssignCompanyAction(
            $user,
            $branch,
            $role,
            $app,
        )->execute();

        return $user;
    }
}
