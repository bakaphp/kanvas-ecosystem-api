<?php

declare(strict_types=1);

namespace Tests\Guild\Organizations;

use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OrganizationNameNormalizerServiceTest extends TestCase
{
    #[DataProvider('nameProvider')]
    public function test_normalizes_legal_suffix_variants(string $input, string $expected): void
    {
        $this->assertSame($expected, OrganizationNameNormalizerService::normalize($input));
    }

    public static function nameProvider(): array
    {
        return [
            'plain name untouched' => ['MCTEKK', 'MCTEKK'],
            'SRL stripped' => ['MCTEKK SRL', 'MCTEKK'],
            'SRL with dots' => ['MCTEKK S.R.L.', 'MCTEKK'],
            'SA with comma and spaces' => ['ALPA IMPORT, S. A.', 'ALPA IMPORT'],
            'SAS with comma' => ['Grupo Carol, SAS', 'Grupo Carol'],
            'SA dotted with comma' => ['GRUPO CAROL, S.A.', 'GRUPO CAROL'],
            'SAS before SA precedence' => ['Foo S.A.S', 'Foo'],
            'EIRL stripped' => ['Bar EIRL', 'Bar'],
            'C por A stripped' => ['Tienda C. por A.', 'Tienda'],
            'LLC stripped' => ['Acme LLC', 'Acme'],
            'Inc stripped' => ['Globex, Inc.', 'Globex'],
            'and Co stripped' => ['Wonka & Co', 'Wonka'],
            'collapses internal whitespace' => ['  Multi   Space   Co  ', 'Multi Space'],
            'lone suffix word kept' => ['SA', 'SA'],
            'empty stays empty' => ['', ''],
            'multiword name with no suffix' => ['Banco Popular Dominicano', 'Banco Popular Dominicano'],
            'GmbH stripped' => ['Muster GmbH', 'Muster'],
            'mbB stripped' => ['Penner + Partner WP StB mbB', 'Penner + Partner WP StB'],
            'GbR stripped' => ['Schmidt & Weber GbR', 'Schmidt & Weber'],
            'GmbH & Co KG stripped' => ['Muster GmbH & Co. KG', 'Muster'],
            'AG stripped' => ['Beispiel AG', 'Beispiel'],
            'e.V. stripped' => ['Musterverein e.V.', 'Musterverein'],
        ];
    }
}
