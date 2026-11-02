<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Concerns;

use Kanvas\Templates\Support\TemplateSandbox;

/**
 * A role section configured on the agent wins over the handler's local default; only the configured
 * text goes through the template sandbox, so a default never has to be template-safe. Requires the
 * `$agent` property.
 */
trait RendersRoleSections
{
    /**
     * @param array<string, mixed> $context
     */
    protected function renderRoleSection(string $key, string $default, array $context = []): string
    {
        $configured = trim($this->agent->roleSection($key, "\n"));

        return $configured !== '' ? TemplateSandbox::render($configured, $context) : $default;
    }
}
