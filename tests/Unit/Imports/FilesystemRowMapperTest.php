<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Kanvas\Filesystem\Services\FilesystemRowMapper;
use Tests\TestCaseUnit;

class FilesystemRowMapperTest extends TestCaseUnit
{
    private const array ROW = [
        'Make' => 'Cadillac',
        'Model' => 'XT6',
        'Year' => '2026',
        'Series' => '',
        'MSRP' => '',
        'Price' => '59900',
        'New/Used' => 'Used',
        'Zero' => '0',
        'extra.notes' => 'column wins',
        'extra' => ['warehouse_id' => 4, 'nested' => ['channel' => 7]],
    ];

    public function testConcatJoinsNonEmptyPartsWithTheSeparator(): void
    {
        $this->assertSame(
            'Cadillac XT6 2026',
            FilesystemRowMapper::resolve(['$concat' => ['Make', 'Model', 'Year', 'Series']], self::ROW)
        );
        $this->assertSame(
            'Cadillac-2026',
            FilesystemRowMapper::resolve(['$concat' => ['Make', 'Year'], '$sep' => '-'], self::ROW)
        );
    }

    public function testConcatAcceptsConstantsAndNestedExpressions(): void
    {
        $this->assertSame(
            'New Cadillac 59900',
            FilesystemRowMapper::resolve(
                ['$concat' => ['_New', 'Make', ['$coalesce' => ['MSRP', 'Price']]]],
                self::ROW
            )
        );
    }

    public function testCoalesceReturnsTheFirstNonEmptyValue(): void
    {
        $this->assertSame('59900', FilesystemRowMapper::resolve(['$coalesce' => ['MSRP', 'Price']], self::ROW));
        $this->assertNull(FilesystemRowMapper::resolve(['$coalesce' => ['MSRP', 'Series', 'Missing']], self::ROW));
    }

    public function testCoalesceKeepsTheStringZero(): void
    {
        $this->assertSame('0', FilesystemRowMapper::resolve(['$coalesce' => ['MSRP', 'Zero', 'Price']], self::ROW));
    }

    public function testMapLooksUpCaseInsensitivelyAndFallsBackToDefault(): void
    {
        $values = ['New' => 1, 'N' => 1, 'used' => 0, 'U' => 0];

        $this->assertSame(0, FilesystemRowMapper::resolve(['$map' => 'New/Used', 'values' => $values], self::ROW));
        $this->assertNull(FilesystemRowMapper::resolve(['$map' => 'Make', 'values' => $values], self::ROW));
        $this->assertSame(
            'other',
            FilesystemRowMapper::resolve(['$map' => 'Make', 'values' => $values, 'default' => 'other'], self::ROW)
        );
    }

    public function testExtraDotPathReadsTheImportExtra(): void
    {
        $this->assertSame(4, FilesystemRowMapper::resolve('extra.warehouse_id', self::ROW));
        $this->assertSame(7, FilesystemRowMapper::resolve('extra.nested.channel', self::ROW));
        $this->assertNull(FilesystemRowMapper::resolve('extra.missing', self::ROW));
        $this->assertNull(FilesystemRowMapper::resolve('extra.warehouse_id', ['Make' => 'Cadillac']));
    }

    public function testARealColumnWinsOverTheExtraPrefix(): void
    {
        $this->assertSame('column wins', FilesystemRowMapper::resolve('extra.notes', self::ROW));
    }

    public function testPlainValuesKeepTheirExistingMeaning(): void
    {
        $this->assertSame('fixed', FilesystemRowMapper::resolve('_fixed', self::ROW));
        $this->assertSame('Cadillac', FilesystemRowMapper::resolve('Make', self::ROW));
        $this->assertNull(FilesystemRowMapper::resolve('Missing', self::ROW));
        $this->assertTrue(FilesystemRowMapper::resolve(true, self::ROW));
        $this->assertSame(3, FilesystemRowMapper::resolve(3, self::ROW));
    }

    public function testResolveTextTrimsScalarsAndRefusesArrays(): void
    {
        $this->assertSame('Cadillac', FilesystemRowMapper::resolveText('Make', ['Make' => '  Cadillac ']));
        $this->assertSame('2026', FilesystemRowMapper::resolveText(2026, []));
        $this->assertNull(FilesystemRowMapper::resolveText('Series', self::ROW));
        $this->assertNull(FilesystemRowMapper::resolveText('extra', self::ROW));
    }

    public function testIsExpressionOnlyMatchesReservedKeys(): void
    {
        $this->assertTrue(FilesystemRowMapper::isExpression(['$coalesce' => ['A']]));
        $this->assertFalse(FilesystemRowMapper::isExpression(['name' => '_year', 'value' => 'Year']));
        $this->assertFalse(FilesystemRowMapper::isExpression('Make'));
    }
}
