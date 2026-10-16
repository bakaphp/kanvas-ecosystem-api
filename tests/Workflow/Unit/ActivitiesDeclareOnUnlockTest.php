<?php

declare(strict_types=1);

namespace Tests\Workflow\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCaseUnit;
use Workflow\Activity;

/**
 * durable-workflow's ActivityMiddleware assigns $job->onUnlock on every activity run without
 * declaring it, which PHP 8.2+ logs as a dynamic-property deprecation per run — one line per
 * activity execution in production. Every class that extends the vendor Activity directly must
 * declare the property itself; everything under KanvasActivity inherits it.
 */
final class ActivitiesDeclareOnUnlockTest extends TestCaseUnit
{
    public function testEveryDirectActivitySubclassDeclaresOnUnlock(): void
    {
        $missing = [];

        foreach ($this->directActivitySubclasses() as $class) {
            if (! new ReflectionClass($class)->hasProperty('onUnlock')) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These classes extend Workflow\Activity directly but do not declare public ?Closure $onUnlock — '
            . 'extend KanvasActivity or declare the property: ' . implode(', ', $missing)
        );
    }

    /**
     * @return list<class-string>
     */
    private function directActivitySubclasses(): array
    {
        $classes = [];

        foreach (['src', 'app'] as $root) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());

                if (! preg_match('/^use Workflow\\\\Activity;/m', $source)
                    || ! preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
                    || ! preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)\s+extends\s+Activity\b/m', $source, $class)
                ) {
                    continue;
                }

                $fqcn = $namespace[1] . '\\' . $class[1];

                if (is_subclass_of($fqcn, Activity::class)) {
                    $classes[] = $fqcn;
                }
            }
        }

        $this->assertNotEmpty($classes, 'Expected at least KanvasActivity to be found');

        return $classes;
    }
}
