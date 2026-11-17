<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\ResourceScheduleValidator;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\Event\Concerns\CreatesScheduleRules;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ResourceScheduleOperatingPeriodsTest extends TestCase
{
    use CreatesScheduleRules;
    use DatabaseTransactions;
    use InventoryCases;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'event'];

    protected Apps $apps;
    protected mixed $company;
    protected Products $product;
    protected Variants $variant;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->company->timezone = 'UTC';
        $this->company->saveOrFail();

        $response = $this->graphQLData($this->createProduct(), 'createProduct');
        $this->product = Products::find($response['id']);
        $this->variant = $this->product->variants()->first();
    }

    public function testOvernightPeriodIsOpenOnBothSidesOfMidnight(): void
    {
        $this->addScheduleRule($this->variant, 'friday', ['metadata' => ['periods' => [['open' => '18:00', 'close' => '02:00']]]]);

        $validator = new ResourceScheduleValidator($this->variant, $this->apps);

        $this->assertTrue($validator->isOpen(Carbon::parse('2026-12-11 23:00:00', 'UTC')));
        $this->assertTrue($validator->isOpen(Carbon::parse('2026-12-12 01:30:00', 'UTC')));
        $this->assertFalse($validator->isOpen(Carbon::parse('2026-12-12 03:00:00', 'UTC')));
        $this->assertFalse($validator->isOpen(Carbon::parse('2026-12-11 17:00:00', 'UTC')));
    }

    public function testOpenCheckAndClosedMessageStillUseTheSharedPeriods(): void
    {
        $this->addScheduleRule($this->variant, 'friday', ['day_rrule' => 'RRULE:FREQ=DAILY;BYHOUR=9,10,11']);

        $validator = new ResourceScheduleValidator($this->variant, $this->apps);

        $this->assertTrue($validator->isOpen(Carbon::parse('2026-12-11 11:59:00', 'UTC')));
        $this->assertFalse($validator->isOpen(Carbon::parse('2026-12-11 12:00:00', 'UTC')));

        try {
            $validator->validateAvailability(Carbon::parse('2026-12-11 15:00:00', 'UTC'));
            $this->fail('Expected a closed validation error');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Operating hours: 09:00 - 12:00', $e->getMessage());
        }
    }
}
