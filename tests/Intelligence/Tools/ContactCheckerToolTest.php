<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Tools\ContactCheckerTool;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class ContactCheckerToolTest extends TestCase
{
    /**
     * The agent's `role` sections are JSON — sometimes an array of lines, sometimes a single
     * string. The tool must render both without blowing up implode() with a TypeError
     * (KANVAS-ECOSYSTEM-5RW).
     */
    public function testRenderRoleSectionHandlesStringAndArray(): void
    {
        $tool = $this->toolWithRole([
            'background' => 'You are a strict CRM analyst.',        // string form (crashing case)
            'steps' => ['Step one.', 'Step two.'],                 // array form
        ]);

        $render = new ReflectionMethod($tool, 'renderRoleSection');

        $this->assertSame('You are a strict CRM analyst.', $render->invoke($tool, 'background', ' ', []));
        $this->assertSame("Step one.\nStep two.", $render->invoke($tool, 'steps', "\n", []));
    }

    public function testRenderRoleSectionHandlesMissingSectionAndNullRole(): void
    {
        $render = new ReflectionMethod(ContactCheckerTool::class, 'renderRoleSection');

        $this->assertSame('', $render->invoke($this->toolWithRole(['steps' => ['x']]), 'background', ' ', []));
        $this->assertSame('', $render->invoke($this->toolWithRole(null), 'background', ' ', []));
    }

    private function toolWithRole(?array $role): ContactCheckerTool
    {
        $tool = new ReflectionClass(ContactCheckerTool::class)->newInstanceWithoutConstructor();

        $agent = new Agent();
        $agent->role = $role;

        new ReflectionProperty($tool, 'agent')->setValue($tool, $agent);

        return $tool;
    }
}
