<?php

declare(strict_types=1);

namespace Kanvas\Templates\Contracts;

/**
 * What a model shows a sandboxed template, in place of its attributes. Implement it on any model
 * whose row carries something a template author must never print (recovery codes, keys, billing
 * ids): an HTML template can send whatever it prints to any host through an image URL.
 */
interface ExposesTemplateData
{
    /**
     * @return array<string, mixed>
     */
    public function toTemplateData(): array;
}
