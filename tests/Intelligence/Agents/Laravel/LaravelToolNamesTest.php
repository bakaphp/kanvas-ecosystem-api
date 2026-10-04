<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Laravel;

use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Tools\Common\CurrentTimeTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\CreateLeadTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\LeadSearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Templates\UpdateTemplateTool;
use Kanvas\Intelligence\Agents\Services\AgentToolDiscoveryService;
use Laravel\Ai\Tools\ToolNameResolver;
use Tests\TestCase;

/**
 * The names the model is shown: every prompt refers to a tool by its snake name, and Gemini rejects the whole
 * request over one function name outside `KanvasToolInterface::FUNCTION_NAME_PATTERN`.
 */
final class LaravelToolNamesTest extends TestCase
{
    public function testToolsAreNamedTheWayThePromptsCallThem(): void
    {
        $this->assertSame('create_lead', new CreateLeadTool()->name());
        $this->assertSame('update_template', new UpdateTemplateTool()->name());
        $this->assertSame('search_leads', new LeadSearchTool()->name());
        $this->assertSame('get_current_time', new CurrentTimeTool()->name());
    }

    public function testNoLaravelToolIsDeclaredByItsClassName(): void
    {
        $classes = array_filter(
            array_column(new AgentToolDiscoveryService()->discover(), 'class'),
            fn (string $class): bool => is_subclass_of($class, KanvasToolInterface::class),
        );

        $this->assertNotEmpty($classes);

        foreach ($classes as $class) {
            $name = ToolNameResolver::resolve(new $class());

            $this->assertNotSame(class_basename($class), $name, "{$class} is shown to the model as its class name");
            $this->assertMatchesRegularExpression(KanvasToolInterface::FUNCTION_NAME_PATTERN, $name, "{$class} declares a name Gemini rejects");
        }
    }
}
