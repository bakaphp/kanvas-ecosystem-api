<?php

declare(strict_types=1);

namespace Tests\Scribe\Regional;

use Kanvas\Scribe\Regional\DataTransferObject\NcfValidation;
use Kanvas\Scribe\Regional\Validators\DominicanRepublic\NcfValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NcfValidatorTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: ?string}>
     */
    public static function cases(): array
    {
        return [
            'paper crédito fiscal' => ['B0100000123', null, '01'],
            'with dashes and spaces' => ['b01-0000 0123', null, '01'],
            'e-NCF crédito fiscal' => ['E310000000045', null, '31'],
            'régimen especial' => ['B1400000001', null, '14'],
            'gubernamental e-NCF' => ['E450000000001', null, '45'],
            'missing' => [null, NcfValidation::MISSING, null],
            'blank' => ['  ', NcfValidation::MISSING, null],
            'too short' => ['B01123', NcfValidation::BAD_FORMAT, null],
            'e-NCF with 8 digits' => ['E3100000001', NcfValidation::BAD_FORMAT, null],
            'unknown series' => ['A0100000123', NcfValidation::BAD_FORMAT, null],
            'consumer invoice' => ['B0200000123', NcfValidation::WRONG_TYPE, '02'],
            'consumer e-NCF' => ['E320000000045', NcfValidation::WRONG_TYPE, '32'],
            'credit note' => ['E340000000045', NcfValidation::WRONG_TYPE, '34'],
            'buyer-issued purchase voucher' => ['B1100000001', NcfValidation::WRONG_TYPE, '11'],
        ];
    }

    #[DataProvider('cases')]
    public function test_ncf_format_and_type(?string $ncf, ?string $code, ?string $type): void
    {
        $result = new NcfValidator()->validate($ncf);

        $this->assertSame($code, $result->code);
        $this->assertSame($code === null, $result->isValid());
        $this->assertSame($type, $result->type);
        if ($code !== null) {
            $this->assertNotEmpty($result->reason);
        }
    }

    public function test_a_wrong_type_asks_for_the_credit_series_of_the_same_kind(): void
    {
        $this->assertStringContainsString('(E31)', (string) new NcfValidator()->validate('E320000000045')->reason);
        $this->assertStringContainsString('(B01)', (string) new NcfValidator()->validate('B0200000123')->reason);
    }
}
