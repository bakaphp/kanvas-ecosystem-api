<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Notifications\Concerns;

use Kanvas\Notifications\Concerns\PushesTitleAndMessageFromData;

/**
 * Roadside pushes carry their title/message in the constructor data with no stored push template
 * (push_template is null). Expo renders through getPushContent(), which would otherwise try to
 * render a missing DB template and drop the push, so emit the JSON shape it parses instead.
 */
trait PushesConstructorContent
{
    use PushesTitleAndMessageFromData;

    protected function getPushTemplate(): string
    {
        return json_encode([
            'title' => $this->data['title'] ?? '',
            'message' => $this->data['message'] ?? '',
            'subtitle' => null,
        ], JSON_THROW_ON_ERROR);
    }
}
