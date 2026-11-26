<?php

declare(strict_types=1);

namespace Kanvas\Communication\Contracts;

use Kanvas\Communication\Connections\DataTransferObject\Retention;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;

/**
 * Anything a message can leave through. A vendor a tenant integrates is a CommunicationProvider; what
 * every company has without integrating anything (its own SMTP) is a BuiltInCarrier.
 */
interface CommunicationCarrier
{
    /**
     * The name the registry and every communication row know it by.
     */
    public static function slug(): string;

    /**
     * @return array<value-of<ChannelTypeEnum>, class-string<Transport>> the transport serving each channel
     */
    public static function transports(): array;

    public static function eventRetention(): Retention;
}
