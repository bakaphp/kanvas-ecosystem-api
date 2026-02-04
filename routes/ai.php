<?php

use App\Http\Middleware\KanvasAppKeyMiddleware;
use Laravel\Mcp\Facades\Mcp;
use Nuwave\Lighthouse\Http\Middleware\AttemptAuthentication;

Mcp::web('mcp/kanvas', \App\Mcp\Servers\KanvasServer::class)
->middleware([
        KanvasAppKeyMiddleware::class,
        AttemptAuthentication::class,
        'throttle:graphql',
    ]);
