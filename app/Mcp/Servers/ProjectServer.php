<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\Attachments\AttachFileTool;
use App\Mcp\Tools\Attachments\ListFilesTool;
use App\Mcp\Tools\Columns\CreateBoardColumnTool;
use App\Mcp\Tools\Columns\ListBoardColumnsTool;
use App\Mcp\Tools\Columns\RenameBoardColumnTool;
use App\Mcp\Tools\Columns\ReorderBoardColumnsTool;
use App\Mcp\Tools\Projects\CreateProjectTool;
use App\Mcp\Tools\Projects\DeleteProjectTool;
use App\Mcp\Tools\Projects\GetProjectTool;
use App\Mcp\Tools\Projects\ListAgentsTool;
use App\Mcp\Tools\Projects\ListProjectsTool;
use App\Mcp\Tools\Projects\UpdateProjectTool;
use App\Mcp\Tools\Subtasks\AddSubtaskTool;
use App\Mcp\Tools\Subtasks\DeleteSubtaskTool;
use App\Mcp\Tools\Subtasks\UpdateSubtaskStatusTool;
use App\Mcp\Tools\Tasks\CreateTaskTool;
use App\Mcp\Tools\Tasks\DeleteTaskTool;
use App\Mcp\Tools\Tasks\GetTaskTool;
use App\Mcp\Tools\Tasks\ListTasksTool;
use App\Mcp\Tools\Tasks\UpdateTaskTool;
use Laravel\Mcp\Server;

class ProjectServer extends Server
{
    protected string $name = 'Kanvas Projects';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
    Manage the AI projects of one Kanvas company: the project, its board columns, the tasks on
    the board, their subtasks and the files attached to any of them.

    - A project has a board. The board has ordered columns, each identified by a stable `key`.
    - A task is a card on the board. It sits in one column and takes that column's status.
    - A subtask is a checklist step inside a task. Completing subtasks moves the task's completion percentage.
    - Files can be attached to a project, a task or a subtask.

    Start with `list_projects`, then `get_project` to see the board. Ids are only valid inside the
    company this connection is scoped to; never guess one.
    MARKDOWN;

    /** The package default of 15 would split this server's tool list across two tools/list pages. */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        ListProjectsTool::class,
        GetProjectTool::class,
        CreateProjectTool::class,
        UpdateProjectTool::class,
        DeleteProjectTool::class,
        ListAgentsTool::class,
        ListBoardColumnsTool::class,
        CreateBoardColumnTool::class,
        RenameBoardColumnTool::class,
        ReorderBoardColumnsTool::class,
        ListTasksTool::class,
        GetTaskTool::class,
        CreateTaskTool::class,
        UpdateTaskTool::class,
        DeleteTaskTool::class,
        AddSubtaskTool::class,
        UpdateSubtaskStatusTool::class,
        DeleteSubtaskTool::class,
        AttachFileTool::class,
        ListFilesTool::class,
    ];
}
