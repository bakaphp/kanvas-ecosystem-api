<?php

declare(strict_types=1);

namespace Tests\Unit;

use Baka\Traits\KanvasCascadeSoftDeletesTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;
use PHPUnit\Framework\TestCase;

class KanvasCascadeSoftDeletesTraitTest extends TestCase
{
    public function testGetKanvasCascadeSoftDeletesReturnsConfiguredRelationships(): void
    {
        $model = new class () extends Model {
            use KanvasCascadeSoftDeletesTrait;

            protected array $cascadeSoftDeletes = ['children', 'items'];
        };

        $result = (fn () => $this->getKanvasCascadeSoftDeletes())->call($model);

        $this->assertEquals(['children', 'items'], $result);
    }

    public function testGetKanvasCascadeSoftDeletesReturnsEmptyWhenNotConfigured(): void
    {
        $model = new class () extends Model {
            use KanvasCascadeSoftDeletesTrait;
        };

        $result = (fn () => $this->getKanvasCascadeSoftDeletes())->call($model);

        $this->assertEquals([], $result);
    }

    public function testValidateThrowsOnInvalidRelationship(): void
    {
        $model = new class () extends Model {
            use KanvasCascadeSoftDeletesTrait;

            protected array $cascadeSoftDeletes = ['nonExistent'];
        };

        $this->expectException(LogicException::class);

        (fn () => $this->validateKanvasCascadeRelationship('nonExistent'))->call($model);
    }

    public function testValidatePassesForValidRelationship(): void
    {
        $relation = $this->createMock(Relation::class);

        $model = new class ($relation) extends Model {
            use KanvasCascadeSoftDeletesTrait;

            private Relation $mockRelation;

            public function __construct(Relation $relation)
            {
                $this->mockRelation = $relation;
            }

            public function items(): Relation
            {
                return $this->mockRelation;
            }
        };

        (fn () => $this->validateKanvasCascadeRelationship('items'))->call($model);

        $this->assertTrue(true);
    }

    public function testValidateThrowsWhenMethodIsNotARelationship(): void
    {
        $model = new class () extends Model {
            use KanvasCascadeSoftDeletesTrait;

            public function notARelation(): string
            {
                return 'hello';
            }
        };

        $this->expectException(LogicException::class);

        (fn () => $this->validateKanvasCascadeRelationship('notARelation'))->call($model);
    }
}
