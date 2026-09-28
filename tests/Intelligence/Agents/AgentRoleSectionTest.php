<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

class AgentRoleSectionTest extends TestCase
{
    public function testStringSectionIsReturnedAsIs(): void
    {
        $agent = new Agent();
        $agent->role = ['background' => '# SYSTEM PROMPT'];

        $this->assertSame('# SYSTEM PROMPT', $agent->roleSection('background'));
    }

    public function testArraySectionIsJoinedWithSeparator(): void
    {
        $agent = new Agent();
        $agent->role = ['steps' => ['first', 'second']];

        $this->assertSame('first second', $agent->roleSection('steps'));
        $this->assertSame("first\nsecond", $agent->roleSection('steps', "\n"));
    }

    public function testMissingSectionOrRoleIsEmpty(): void
    {
        $agent = new Agent();

        $this->assertSame('', $agent->roleSection('background'));

        $agent->role = ['background' => 'only background'];

        $this->assertSame('', $agent->roleSection('output'));
    }
}
