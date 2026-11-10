<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\GraphQL\Ecosystem\Queries\CustomFields\CustomFieldQuery;
use App\GraphQL\Ecosystem\Queries\CustomFields\CustomFieldValueResolver;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Date;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use PHPUnit\Framework\TestCase;

final class LeadAiModeCustomFieldTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Date::swap(new DateFactory());
    }

    public function testReadsFollowHoursAndTypeChangesWithoutChangingStoredMode(): void
    {
        [$lead, $state] = $this->lead();
        $resolver = new CustomFieldValueResolver();

        $this->assertSame('IDLE', $resolver->resolve($lead, 'ai_mode'));
        $state->open = false;
        $this->assertSame('FULL_ON', $resolver->resolve($lead, 'ai_mode'));
        $this->assertSame('FULL_ON', $resolver->resolveAll($lead, $state->fields)['ai_mode']);
        $state->open = true;
        $state->config = ['internet_ai_mode_open_default' => 'SUPPORT'];
        $this->assertSame('SUPPORT', $resolver->resolve($lead, 'ai_mode'));
        $this->assertSame('IDLE', $lead->get('ai_mode'));
    }

    public function testManualModeWinsInBothScenarios(): void
    {
        [$lead, $state] = $this->lead();
        $state->manual = true;
        $resolver = new CustomFieldValueResolver();
        foreach ([true, false] as $open) {
            $state->open = $open;
            $this->assertSame('IDLE', $resolver->resolve($lead, 'ai_mode'));
            $this->assertSame('IDLE', $resolver->resolveAll($lead, $state->fields)['ai_mode']);
        }
    }

    public function testMissingDefaultsUseStoredThenCompanyFallback(): void
    {
        [$lead, $state] = $this->lead();
        $state->config = [];
        $resolver = new CustomFieldValueResolver();
        $this->assertSame('IDLE', $resolver->resolve($lead, 'ai_mode'));
        unset($state->fields['ai_mode']);
        $state->fallback = 'SUPPORT';
        $this->assertSame('SUPPORT', $resolver->resolve($lead, 'ai_mode'));
        $this->assertSame(['ai_mode' => 'SUPPORT'], $resolver->resolveAll($lead, []));
        $this->assertSame([], $state->fields);
    }

    public function testUnconfiguredModeDoesNotInventAFieldAndInvalidStoredModeIsNull(): void
    {
        [$lead, $state] = $this->lead();
        $state->config = [];
        $state->fields = [];
        $resolver = new CustomFieldValueResolver();
        $this->assertNull($resolver->resolve($lead, 'ai_mode'));
        $this->assertSame([], $resolver->resolveAll($lead, []));
        $state->fields = ['ai_mode' => 'invalid'];
        $this->assertSame(['ai_mode' => null], $resolver->resolveAll($lead, $state->fields));
    }

    public function testVirtualModeIsPaginatedExactlyOnceWithEntityMetadata(): void
    {
        [$lead, $state] = $this->lead();
        $state->fields = ['other' => 42];
        $state->open = false;
        $query = new CustomFieldQuery();
        $context = Mockery::mock(GraphQLContext::class);
        $info = $this->createStub(ResolveInfo::class);
        $first = $query->getAllByGraphType($lead, ['first' => 1, 'page' => 1], $context, $info);
        $second = $query->getAllByGraphType($lead, ['first' => 1, 'page' => 2], $context, $info);
        $third = $query->getAllByGraphType($lead, ['first' => 1, 'page' => 3], $context, $info);

        $this->assertSame(2, $first->total());
        $this->assertSame('other', $first->items()[0]['name']);
        $this->assertSame([['name' => 'ai_mode', 'value' => 'FULL_ON', 'entity_id' => 123, 'model_name' => Lead::class]], $second->items());
        $this->assertSame([], $third->items());
        $this->assertSame(['other' => 42], $state->fields);
    }

    public function testStoredModeIsReplacedInPaginatedResponseWithoutDuplication(): void
    {
        [$lead, $state] = $this->lead();
        $state->open = false;
        $page = new CustomFieldQuery()->getAllByGraphType(
            $lead, ['first' => 25], Mockery::mock(GraphQLContext::class), $this->createStub(ResolveInfo::class)
        );
        $this->assertSame(1, $page->total());
        $this->assertSame('FULL_ON', $page->items()[0]['value']);
        $this->assertSame('IDLE', $state->fields['ai_mode']);
    }

    public function testOtherFieldsAndPeopleRemainUnchanged(): void
    {
        [$lead, $state] = $this->lead();
        $state->fields['first_followup'] = ['ai_mode' => ['value' => 'old']];
        $resolver = new CustomFieldValueResolver();
        $this->assertSame($state->fields['first_followup'], $resolver->resolve($lead, 'first_followup'));
        $person = Mockery::mock(People::class);
        $person->shouldReceive('get')->with('ai_mode')->andReturn('raw-value');
        $this->assertSame('raw-value', $resolver->resolve($person, 'ai_mode'));
        $this->assertSame(['ai_mode' => 'raw-value'], $resolver->resolveAll($person, ['ai_mode' => 'raw-value']));
    }

    /** Exercise the real Lead::resolveAiMode with in-memory storage and relations. */
    private function lead(): array
    {
        $state = (object) [
            'open' => true,
            'manual' => false,
            'fallback' => null,
            'fields' => ['ai_mode' => 'IDLE'],
            'config' => ['ai_mode_open_default' => 'IDLE', 'ai_mode_closed_default' => 'FULL_ON'],
        ];
        $type = Mockery::mock(LeadType::class);
        $type->shouldReceive('offsetExists')->with('config')->andReturn(true);
        $type->shouldReceive('getAttribute')->with('config')->andReturnUsing(fn () => $state->config);
        $relation = Mockery::mock(BelongsTo::class);
        $relation->shouldReceive('first')->andReturn($type);
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('isWithinWorkingHours')->andReturnUsing(fn () => $state->open);
        $company->shouldReceive('get')->with('ai_mode')->andReturnUsing(fn () => $state->fallback);
        $lead = Mockery::mock(Lead::class)->makePartial();
        $lead->shouldReceive('type')->andReturn($relation);
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);
        $lead->shouldReceive('getAttribute')->with('app')->andReturn(null);
        $lead->shouldReceive('get')->andReturnUsing(fn (string $key) => $key === LeadConfigurationEnum::AI_MODE_IS_MANUAL->value ? $state->manual : ($state->fields[$key] ?? null));
        $lead->shouldReceive('getAllFromRedis')->andReturnUsing(fn () => $state->fields);
        $lead->shouldReceive('getKey')->andReturn(123);
        $lead->shouldNotReceive('set');

        return [$lead, $state];
    }
}
