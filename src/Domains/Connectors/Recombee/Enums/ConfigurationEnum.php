<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Enums;

enum ConfigurationEnum: string
{
    case RECOMBEE_DATABASE = 'RECOMBEE_DATABASE';
    case RECOMBEE_API_KEY = 'RECOMBEE_API_KEY';
    case RECOMBEE_REGION = 'RECOMBEE_REGION';
}
