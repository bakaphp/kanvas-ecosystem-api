<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Support\AccountSync;
use PHPUnit\Framework\TestCase;

final class AccountSyncTest extends TestCase
{
    private const HASH_A = '$2y$10$abcdefghijklmnopqrstuuJ6yPqXm1yCwZ3vL0b1tqZ9Y8hTqk3K.';
    private const HASH_B = '$2y$10$zyxwvutsrqponmlkjihgfeJ6yPqXm1yCwZ3vL0b1tqZ9Y8hTqk3K.';

    public function testOnlyBcryptHashesAreFingerprinted(): void
    {
        $this->assertNotNull(AccountSync::passwordFingerprint(self::HASH_A));
        $this->assertNull(AccountSync::passwordFingerprint('plain-password'));
        $this->assertNull(AccountSync::passwordFingerprint(null));
        $this->assertNotSame(AccountSync::passwordFingerprint(self::HASH_A), AccountSync::passwordFingerprint(self::HASH_B));
        $this->assertStringNotContainsString(self::HASH_A, (string) AccountSync::passwordFingerprint(self::HASH_A));
    }

    public function testProfilesCompareByFullNameAndPhoneDigits(): void
    {
        // BrushCrazy keeps one name and formats phones its own way.
        $this->assertSame(
            AccountSync::profileFingerprint('Mary Ann', 'Smith', '406-555-0100'),
            AccountSync::profileFingerprint('mary', 'Ann  Smith', '(406) 555-0100'),
        );
        $this->assertNotSame(
            AccountSync::profileFingerprint('Mary', 'Smith', '406-555-0100'),
            AccountSync::profileFingerprint('Mary', 'Smith', '406-555-0199'),
        );
    }

    public function testBrushCrazyWinsOnlyWhenTheSiteHasNotChangedSinceTheLastSync(): void
    {
        $a = (string) AccountSync::passwordFingerprint(self::HASH_A);
        $b = (string) AccountSync::passwordFingerprint(self::HASH_B);

        // Same value: nothing to do, whatever happened before.
        $this->assertFalse(AccountSync::brushCrazyWins(
            $a,
            $a,
            null,
            true
        ));

        // The site is still at the agreed value: the change was made in BrushCrazy.
        $this->assertTrue(AccountSync::brushCrazyWins(
            $a,
            $b,
            $a,
            false
        ));

        // The site changed since: its own push carries it to BrushCrazy.
        $this->assertFalse(AccountSync::brushCrazyWins(
            $b,
            $a,
            $a,
            true
        ));

        // Before any sync: BrushCrazy's own accounts follow BrushCrazy, the site's keep theirs.
        $this->assertTrue(AccountSync::brushCrazyWins(
            $a,
            $b,
            null,
            true
        ));
        $this->assertFalse(AccountSync::brushCrazyWins(
            $a,
            $b,
            null,
            false
        ));
    }
}
