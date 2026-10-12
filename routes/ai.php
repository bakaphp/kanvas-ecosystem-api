<?php

use App\Http\Middleware\EnsureKanvasMcpContext;
use App\Mcp\Servers\ProjectServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::prefix(config('kanvas.application.routes.prefix'))
    ->middleware([...config('kanvas.application.routes.middleware'), EnsureKanvasMcpContext::class])
    ->group(function (): void {
        Mcp::web('/mcp/projects', ProjectServer::class);
    });
