<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Supadata\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * Supadata has no transcript for this media and could not make one — a video with captions disabled,
 * a clip with no speech, a `mode=native` request against something that never had captions.
 *
 * Distinct from the generic failure on purpose: this is an ordinary outcome of asking about a
 * recording, not a fault, so the tool answers with it instead of reporting it to Sentry. A bad key or
 * a 500 stays a plain ValidationException and does get reported.
 */
class TranscriptUnavailableException extends ValidationException
{
}
