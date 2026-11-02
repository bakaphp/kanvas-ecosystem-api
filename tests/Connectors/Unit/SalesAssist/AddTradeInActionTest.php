<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\SalesAssist;

use Kanvas\Connectors\SalesAssist\Actions\AddTradeInAction;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Guild\Leads\Models\Lead;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddTradeInActionTest extends TestCase
{
    #[DataProvider('tradeForms')]
    public function testStoresTheOriginalTradeInContract(array $data, array $expected): void
    {
        $stored = [];
        $field = $this->createStub(AppsCustomFields::class);
        $lead = $this->getMockBuilder(Lead::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set'])
            ->getMock();
        $lead->expects($this->exactly(2))->method('set')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$stored, $field): AppsCustomFields {
                $stored[$key] = $value;

                return $field;
            });

        $result = new AddTradeInAction($lead, 'Trade-In Ready to be imported into Reynolds.')
            ->execute(['data' => $data]);

        $this->assertSame($expected, $result['tradein_data']);
        $this->assertSame($expected, $stored[LeadCustomFieldEnum::TRADE_IN_DATA->value]);
        $this->assertSame($result['tradein_imported'], $stored[LeadCustomFieldEnum::TRADE_IN_IMPORTED->value]);
        $this->assertSame(1, $result['tradein_imported']['active']);
        $this->assertSame('Trade-In Ready to be imported into Reynolds.', $result['tradein_imported']['message']);
    }

    public static function tradeForms(): iterable
    {
        $expected = [
            'vin' => '1GBJH32K5S3308798',
            'trans' => 'manual',
            'ext_color' => 'silver',
            'int_color' => 'gray',
            'mileage' => '22222',
            'odometer_photo' => ['11854568'],
            'vehicle_photos' => ['11854569'],
        ];
        $legacy = [
            'vehicle_identification_number_(vin)' => '1GBJH32K5S3308798',
            'transmission' => 'manual',
            'exterior_color' => 'silver',
            'interior_color' => 'gray',
            'odometer' => '22222',
            'upload_odometer_photo' => '11854568',
            'upload_vehicle_photo' => '11854569',
        ];
        $labels = ['Vehicle Identification Number (VIN)', 'Transmission', 'Exterior Color',
            'Interior Color', 'Odometer', 'Upload Odometer Photo', 'Upload Vehicle Photo'];
        $extension = array_map(
            static fn (string $label, string $value): array => ['label' => $label, 'value' => $value],
            $labels,
            array_values($legacy),
        );

        yield 'existing form with photo arrays' => [['form' => $expected], $expected];
        yield 'long field names and scalar photos' => [['form' => $legacy], $expected];
        yield 'extension label/value list' => [$extension, $expected];
        yield 'keyed data without form wrapper' => [$legacy, $expected];
        yield 'canonical scalar photos' => [
            ['form' => array_replace($expected, ['odometer_photo' => '11854568', 'vehicle_photos' => '11854569'])],
            $expected,
        ];
        $multiple = array_replace($expected, ['vehicle_photos' => ['11854569', '11854570'], 'year' => 1995]);
        yield 'multiple photos and extra fields' => [['form' => $multiple], $multiple];
        yield 'canonical values take precedence over aliases' => [
            ['form' => $expected + ['odometer' => '999', 'upload_vehicle_photo' => 'wrong']],
            $expected,
        ];
        yield 'empty input' => [[], []];
    }
}
