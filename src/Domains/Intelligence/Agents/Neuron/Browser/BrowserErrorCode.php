<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Browser;

enum BrowserErrorCode: string
{
    case BROWSER_NOT_CONNECTED = 'BROWSER_NOT_CONNECTED';
    case NAVIGATION_FAILED = 'NAVIGATION_FAILED';
    case INVALID_URL = 'INVALID_URL';
    case INVALID_KEY = 'INVALID_KEY';
    case ACTION_TIMEOUT = 'ACTION_TIMEOUT';
    case ELEMENT_NOT_FOUND = 'ELEMENT_NOT_FOUND';
    case STALE_ELEMENT = 'STALE_ELEMENT';
    case SNAPSHOT_FAILED = 'SNAPSHOT_FAILED';
}
