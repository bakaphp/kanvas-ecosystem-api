<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Actions\CreatePassAction;
use Kanvas\Event\Passes\Actions\RedeemPassAction;
use Kanvas\Event\Passes\Actions\ReleasePassAction;
use Kanvas\Event\Passes\Enums\PassFormatEnum;
use Kanvas\Event\Support\Setup;
use Kanvas\Exceptions\ValidationException;
use Tests\Event\Concerns\CreatesEventVersions;
use Tests\TestCase;

class PassRedemptionTest extends TestCase
{
    use CreatesEventVersions;
    use DatabaseTransactions;

    protected $apps;
    protected $user;
    protected $company;
    protected Event $event;
    protected EventVersion $eventVersion;

    protected function connectionsToTransact(): array
    {
        return [null, 'event'];
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();

        new Setup($this->apps, $this->user, $this->company)->run();

        $this->event = $this->createEvent();
        $this->eventVersion = $this->createEventVersion($this->event);
    }

    public function testNumericPinUsesOnlyDigitsAndDefaultLength(): void
    {
        [, $code] = $this->issue(PassFormatEnum::NUMERIC_PIN);

        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
    }

    public function testAlphanumericCodeUsesUnambiguousAlphabetAndDefaultLength(): void
    {
        [, $code] = $this->issue(PassFormatEnum::QR_CODE);

        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/', $code);
    }

    public function testCustomLengthIsHonoredForBothFormats(): void
    {
        [, $pin] = $this->issue(PassFormatEnum::NUMERIC_PIN, codeLength: 10);
        [, $code] = $this->issue(PassFormatEnum::QR_CODE, codeLength: 12);

        $this->assertSame(10, strlen($pin));
        $this->assertSame(12, strlen($code));
    }

    public function testRedeemSetsRedemptionFields(): void
    {
        [$pass, $code] = $this->issue();
        $booking = $this->booking();

        $redeemed = $this->redeem($code, $booking);

        $this->assertSame($pass->getId(), $redeemed->getId());
        $this->assertNotNull($redeemed->redeemed_at);
        $this->assertSame($booking->getId(), (int) $redeemed->redeemed_event_version_id);
    }

    public function testSecondRedeemIsRejected(): void
    {
        [, $code] = $this->issue();
        $this->redeem($code, $this->booking());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid access code.');

        $this->redeem($code, $this->booking());
    }

    public function testReleaseAllowsRedeemingAgain(): void
    {
        [, $code] = $this->issue();
        $booking = $this->booking();
        $this->redeem($code, $booking);

        $released = new ReleasePassAction($this->apps, $booking->getId())->execute();
        $again = $this->redeem($code, $this->booking());

        $this->assertSame(1, $released);
        $this->assertNotNull($again->redeemed_at);
    }

    public function testReleaseIsIdempotent(): void
    {
        [, $code] = $this->issue();
        $booking = $this->booking();
        $this->redeem($code, $booking);

        $this->assertSame(1, new ReleasePassAction($this->apps, $booking->getId())->execute());
        $this->assertSame(0, new ReleasePassAction($this->apps, $booking->getId())->execute());
    }

    public function testExpiredCodeIsRejected(): void
    {
        [$pass, $code] = $this->issue();
        $pass->update(['expiration_date' => Carbon::now()->subDays(2)->toDateString()]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid access code.');

        $this->redeem($code, $this->booking());
    }

    public function testRevokedCodeIsRejected(): void
    {
        [$pass, $code] = $this->issue();
        $pass->update(['revoked_at' => Carbon::now()]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid access code.');

        $this->redeem($code, $this->booking());
    }

    public function testCodeFromAnotherEventVersionIsRejectedWithGenericMessage(): void
    {
        [, $code] = $this->issue();
        $otherVersion = $this->createEventVersion($this->createEvent());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid access code.');

        $this->redeem($code, $this->booking(), $otherVersion);
    }

    public function testUnknownCodeIsRejectedWithGenericMessage(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid access code.');

        $this->redeem('NOSUCHCODE', $this->booking());
    }

    public function testClaimHeldByACancelledBookingCanBeReclaimed(): void
    {
        [, $code] = $this->issue();
        $oldBooking = $this->booking();
        $this->redeem($code, $oldBooking);
        $this->cancel($oldBooking);
        $newBooking = $this->booking();

        $reclaimed = $this->redeem($code, $newBooking);

        $this->assertSame($newBooking->getId(), (int) $reclaimed->redeemed_event_version_id);
    }

    public function testClaimHeldByADeletedBookingCanBeReclaimed(): void
    {
        [, $code] = $this->issue();
        $oldBooking = $this->booking();
        $this->redeem($code, $oldBooking);
        $oldBooking->update(['is_deleted' => 1]);
        $newBooking = $this->booking();

        $reclaimed = $this->redeem($code, $newBooking);

        $this->assertSame($newBooking->getId(), (int) $reclaimed->redeemed_event_version_id);
    }

    public function testClaimHeldByALiveBookingIsNotReclaimed(): void
    {
        [, $code] = $this->issue();
        $this->redeem($code, $this->booking());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid access code.');

        $this->redeem($code, $this->booking());
    }

    public function testCodeLengthBelowMinimumIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->issue(PassFormatEnum::QR_CODE, codeLength: 0);
    }

    public function testLowercasePaddedCodeCanBeRedeemed(): void
    {
        [$pass, $code] = $this->issue(PassFormatEnum::QR_CODE);

        $redeemed = $this->redeem('  ' . strtolower($code) . ' ', $this->booking());

        $this->assertSame($pass->getId(), $redeemed->getId());
    }

    public function testSoftDeletedPassCannotBeFound(): void
    {
        [$pass, $code] = $this->issue();
        $pass->update(['is_deleted' => 1]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid access code.');

        $this->redeem($code, $this->booking());
    }

    public function testCodeExpiringTodayIsRedeemable(): void
    {
        [$pass, $code] = $this->issue();
        $pass->update(['expiration_date' => Carbon::now()->toDateString()]);

        $redeemed = $this->redeem($code, $this->booking());

        $this->assertNotNull($redeemed->redeemed_at);
    }

    public function testCodeExpiredYesterdayIsRejected(): void
    {
        [$pass, $code] = $this->issue();
        $pass->update(['expiration_date' => Carbon::now()->subDay()->toDateString()]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid access code.');

        $this->redeem($code, $this->booking());
    }

    public function testRetryWithTheSameBookingSucceeds(): void
    {
        [$pass, $code] = $this->issue();
        $booking = $this->booking();
        $this->redeem($code, $booking);

        $retried = $this->redeem($code, $booking);

        $this->assertSame($pass->getId(), $retried->getId());
        $this->assertSame($booking->getId(), (int) $retried->redeemed_event_version_id);
    }

    public function testCodeIssuedUnderAnotherCompanyIsRejected(): void
    {
        $otherCompany = Companies::where('id', '!=', $this->company->getId())->firstOrFail();
        $event = $this->createEvent($otherCompany);
        $eventVersion = $this->createEventVersion($event, $otherCompany);
        [, $code] = new CreatePassAction(
            event: $event,
            eventVersion: $eventVersion,
            expirationDate: Carbon::now()->addDays(10)
        )->execute();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid access code.');

        $this->redeem($code, $this->booking(), $eventVersion);
    }

    private function issue(
        PassFormatEnum $format = PassFormatEnum::NUMERIC_PIN,
        ?int $codeLength = null
    ): array {
        return new CreatePassAction(
            event: $this->event,
            eventVersion: $this->eventVersion,
            expirationDate: Carbon::now()->addDays(10),
            format: $format,
            codeLength: $codeLength
        )->execute();
    }

    private function redeem(string $code, EventVersion $booking, ?EventVersion $codeVersion = null): ParticipantPass
    {
        return new RedeemPassAction(
            app: $this->apps,
            company: $this->company,
            code: $code,
            codeVersion: $codeVersion ?? $this->eventVersion,
            booking: $booking
        )->execute();
    }

    private function booking(?Companies $company = null): EventVersion
    {
        return $this->createEventVersion($this->createEvent($company), $company);
    }

    private function cancel(EventVersion $booking): void
    {
        $status = EventStatus::firstOrCreate(
            [
                'companies_id' => $booking->companies_id,
                'apps_id' => $booking->apps_id,
                'name' => EventStatusEnum::CANCELLED->value,
            ],
            ['users_id' => $booking->users_id]
        );

        $booking->event->update(['event_status_id' => $status->getId()]);
    }
}
