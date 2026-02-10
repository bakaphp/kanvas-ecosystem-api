<?php

use App\Http\Middleware\KanvasAppKeyMiddleware;
use Laravel\Mcp\Facades\Mcp;
use Nuwave\Lighthouse\Http\Middleware\AttemptAuthentication;

Mcp::web('mcp/kanvas', \App\Mcp\Servers\KanvasServer::class)->middleware([KanvasAppKeyMiddleware::class, 'auth.mcp']);
Mcp::web('mcp/generative-ai', \App\Mcp\Servers\GenerativeAiServer::class)->middleware([KanvasAppKeyMiddleware::class, 'auth.mcp']);
