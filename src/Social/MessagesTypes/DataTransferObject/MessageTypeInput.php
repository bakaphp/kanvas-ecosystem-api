<?php

declare(strict_types=1);

namespace Kanvas\Social\MessagesTypes\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 *  class MessageTypeInput
 *  @property int $apps_id
 *  @property int $languages_id
 *  @property string $name
 *  @property string $verb
 *  @property string $template
 *  @property string $templates_plura
 */
class MessageTypeInput extends Data
{    
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public int $apps_id = 0,
        public int $languages_id = 0,
        public string $name = '',
        public string $verb = '',
        public string $template = '',
        public string $templates_plura = '',
    ) {
    }
}
