<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser\Tools;

use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserErrorCode;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserSession;
use Kanvas\Intelligence\Agents\Neuron\Browser\BrowserToolException;
use NeuronAI\Tools\Tool;
use Throwable;

abstract class AbstractBrowserTool extends Tool
{
    public function __construct(
        protected readonly BrowserSession $session,
        string $name,
        string $description,
    ) {
        parent::__construct($name, $description);
        $this->setMaxRuns(100);
    }

    /** @return array<string, mixed> */
    protected function safely(callable $action): array
    {
        try {
            return $action();
        } catch (BrowserToolException $exception) {
            return $exception->toToolResponse();
        } catch (Throwable $exception) {
            report($exception);

            return (new BrowserToolException(
                BrowserErrorCode::BROWSER_NOT_CONNECTED,
                'The browser action failed unexpectedly.',
                $exception,
            ))->toToolResponse();
        }
    }
}
