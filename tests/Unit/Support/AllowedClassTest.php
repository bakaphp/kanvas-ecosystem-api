<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Baka\Support\AllowedClass;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Contracts\AgentTypeHandler;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Users\Models\Users;
use Tests\TestCaseUnit;

final class AllowedClassTest extends TestCaseUnit
{
    public function testResolvesAClassImplementingTheContract(): void
    {
        $this->assertSame(
            KanvasGenericNeuronAgent::class,
            AllowedClass::resolve(KanvasGenericNeuronAgent::class, AgentTypeHandler::class)
        );
    }

    public function testRefusesAnythingElse(): void
    {
        $this->assertNull(AllowedClass::resolve(Users::class, AgentTypeHandler::class));
        $this->assertNull(AllowedClass::resolve(KanvasLaravelAgent::class, AgentTypeHandler::class));
        $this->assertNull(AllowedClass::resolve('Not\\A\\RealClass', AgentTypeHandler::class));
        $this->assertNull(AllowedClass::resolve('', AgentTypeHandler::class));
        $this->assertNull(AllowedClass::resolve(null, AgentTypeHandler::class));
    }

    public function testAssertAllowsEmptyAndRefusesAWrongClass(): void
    {
        AllowedClass::assert(null, 'handler', AgentTypeHandler::class);
        AllowedClass::assert('', 'handler', AgentTypeHandler::class);

        $this->expectException(ValidationException::class);

        AllowedClass::assert(Users::class, 'handler', AgentTypeHandler::class);
    }
}
