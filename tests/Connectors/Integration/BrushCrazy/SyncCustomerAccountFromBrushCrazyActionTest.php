<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCustomerAccountFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\CustomerAccountMapper;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class SyncCustomerAccountFromBrushCrazyActionTest extends TestCase
{
    use DatabaseTransactions;

    /** bcrypt of "secret-pass". */
    private const string HASH = '$2y$04$8XyjsVZWwSmI4owWERm4vuNx.k5knFyzw8JPTaHooadcJ5xiGBSLW';

    protected $connectionsToTransact = [null, 'ecosystem'];

    private int $bcUserId;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        Queue::fake();

        $this->bcUserId = random_int(10_000_000, 99_999_999);
    }

    public function testNewCustomerSignsInWithTheirBrushCrazyPasswordAndHearsNothing(): void
    {
        $user = $this->action()->fromRow($this->row());

        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('secret-pass', $user->getAppProfile(app(Apps::class))->password));
        $this->assertEquals(120, $user->get(CustomerAccountMapper::REWARD_POINTS_FIELD));
        $this->assertEquals(35.0, $user->get(CustomerAccountMapper::PAINT_MONEY_FIELD));
        $this->assertFalse((bool) $user->get(CustomFieldEnum::BRUSHCRAZY_NEEDS_PASSWORD->value));
        $this->assertSame($this->bcUserId, (int) $user->get(CustomFieldEnum::BRUSHCRAZY_USER_ID->value));
        $this->assertSame(
            [$this->bcUserId => $user->getId()],
            SyncCustomerAccountFromBrushCrazyAction::userIdsFor(app(Apps::class), [$this->bcUserId])
        );

        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    /** Someone who signed up on the new site owns their password; BrushCrazy only refreshes balances. */
    public function testCustomerAlreadyInTheAppKeepsTheirPassword(): void
    {
        $first = $this->action()->fromRow($this->row());
        $profile = $first->getAppProfile(app(Apps::class));
        $profile->password = Hash::make('chosen-on-the-site');
        $profile->saveQuietly();

        $again = $this->action()->fromRow($this->row([
            'password' => '$2y$04$Q4kQ3vI2z8V0w5e3l3Yp4uX3eFJ0aW8x9Yb2c1d0e9f8g7h6i5j4k',
            'points' => 150,
        ]));

        $this->assertSame($first->getId(), $again->getId());
        $this->assertTrue(Hash::check('chosen-on-the-site', $again->getAppProfile(app(Apps::class))->password));
        $this->assertEquals(150, $again->get(CustomerAccountMapper::REWARD_POINTS_FIELD));
        $this->assertSame(1, Users::where('email', $this->email())->count());
    }

    public function testWithoutACustomersBranchNoLoginIsMade(): void
    {
        $this->assertNull(new SyncCustomerAccountFromBrushCrazyAction(app(Apps::class))->fromRow($this->row()));
        $this->assertSame(0, Users::where('email', $this->email())->count());
    }

    private function action(): SyncCustomerAccountFromBrushCrazyAction
    {
        return new SyncCustomerAccountFromBrushCrazyAction(
            app(Apps::class),
            branch: auth()->user()->getCurrentCompany()->defaultBranch,
        );
    }

    private function email(): string
    {
        return 'bc-' . $this->bcUserId . '@example.com';
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function row(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => $this->bcUserId,
            'name' => 'Jane Doe',
            'display_name' => 'Jane',
            'email' => $this->email(),
            'phone_number' => null,
            'password' => self::HASH,
            'registration_method' => 'Website',
            'birthday' => null,
            'points' => 120,
            'rewards' => '{"amount":3500,"currency":"USD"}',
            'card' => null,
            'deleted_at' => null,
            'created_at' => '2020-01-01 00:00:00',
        ]);
    }
}
