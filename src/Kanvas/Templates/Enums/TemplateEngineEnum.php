<?php

declare(strict_types=1);

namespace Kanvas\Templates\Enums;

/**
 * BLADE compiles to PHP, so a blade row is set by hand in the database and never by code: the
 * model refuses to create one, or to change or delete one, through Eloquent.
 */
enum TemplateEngineEnum: string
{
    case TWIG = 'twig';
    case BLADE = 'blade';
}
