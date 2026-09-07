<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use ReflectionClass;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The guard is only real if its ledger survives NeuronAI's per-call `clone $tool`. A shallow clone
 * shares object properties, so a ledger built in the constructor is shared by every call of the turn
 * — and one built lazily is built on the clone, giving each call its own empty ledger and silently
 * disabling the guard. That is how the trait shipped, unused, before KANVAS-ECOSYSTEM-6A1.
 */
final class GuardsRepeatCallsTest extends TestCase
{
    private const string TOOLS_PATH = 'src/Domains/Intelligence/Agents/Neuron/Tools';

    public function testEveryGuardedToolInitialisesItsLedgerInTheConstructor(): void
    {
        $guarded = $this->guardedTools();

        $this->assertNotEmpty($guarded, 'No tool uses GuardsRepeatCalls — did the trait lose its last caller?');

        foreach ($guarded as $class) {
            $constructor = new ReflectionClass($class)->getConstructor();

            $this->assertNotNull($constructor, $class . ' uses GuardsRepeatCalls but has no constructor to init it.');
            $this->assertSame(
                0,
                $constructor->getNumberOfRequiredParameters(),
                $class . ' takes constructor arguments; add it to this test explicitly so the guard stays covered.',
            );

            $tool = new $class();

            $this->assertTrue(
                new ReflectionProperty($tool, 'repeatLedger')->isInitialized($tool),
                $class . ' must call initRepeatGuard() in its constructor, or the guard never fires: NeuronAI '
                    . 'clones the tool per call, so a lazily built ledger is built on the clone and is empty '
                    . 'for every call.',
            );
        }
    }

    public function testTheLedgerIsSharedByTheClonesNeuronMakesForEachCall(): void
    {
        foreach ($this->guardedTools() as $class) {
            $registered = new $class();

            $this->assertSame(
                new ReflectionProperty($registered, 'repeatLedger')->getValue($registered),
                new ReflectionProperty($registered, 'repeatLedger')->getValue(clone $registered),
                $class . ' does not share its repeat ledger with its clones.',
            );
        }
    }

    /**
     * @return array<int, class-string>
     */
    private function guardedTools(): array
    {
        $files = new Finder()
            ->files()
            ->in(base_path(self::TOOLS_PATH))
            ->name('*.php')
            ->notPath('Traits')
            ->contains('use GuardsRepeatCalls;');

        return array_values(array_map(
            static fn (SplFileInfo $file): string => 'Kanvas\\Intelligence\\Agents\\Neuron\\Tools\\'
                . str_replace('/', '\\', $file->getRelativePath())
                . ($file->getRelativePath() === '' ? '' : '\\')
                . $file->getBasename('.php'),
            iterator_to_array($files),
        ));
    }
}
