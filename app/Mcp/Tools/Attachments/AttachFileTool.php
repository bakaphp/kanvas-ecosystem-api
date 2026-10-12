<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Attachments;

use App\GraphQL\Concerns\ActingContext;
use App\Mcp\Tools\Concerns\ResolvesAttachmentTarget;
use App\Mcp\Tools\KanvasTool;
use Baka\Contracts\CompanyInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use Kanvas\Users\Models\Users;
use Laravel\Mcp\Request;
use Override;

class AttachFileTool extends KanvasTool
{
    use AttachesFileToEntity;
    use ResolvesAttachmentTarget;

    protected string $name = 'attach_file';

    protected string $description = 'Attach a file to a project, a task or a subtask. Pass `content` with text you wrote '
        . 'plus a `file_name` ending in .md, .txt, .csv or .json; or pass `file_url` for a file that '
        . 'already exists at a public URL. Send one of the two, never both.';

    protected Apps $app;

    protected CompanyInterface $company;

    protected Users $user;

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->attachmentTargetSchema($schema),
            'file_name' => $schema->string()
                ->description('Name to store it under, including extension — e.g. "brief.md". Defaults to the URL\'s '
                    . 'own file name.'),
            'content' => $schema->string()
                ->description('Full text of the document to store. Mutually exclusive with file_url.'),
            'file_url' => $schema->string()
                ->description('Public URL to download the file from. Mutually exclusive with content.'),
        ];
    }

    #[Override]
    protected function execute(Request $request, ActingContext $ctx): array
    {
        $input = $request->validate([
            'target_type' => ['required', 'string'],
            'target_id' => ['required', 'integer'],
            'file_name' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'file_url' => ['nullable', 'string'],
        ]);

        $this->app = $ctx->app;
        $this->company = $ctx->company;
        $this->user = $ctx->user;

        $result = $this->attachFileToEntity(
            entity: $this->attachmentTarget($input['target_type'], (int) $input['target_id'], $ctx),
            entityLabel: $input['target_type'],
            fileUrl: $input['file_url'] ?? null,
            content: $input['content'] ?? null,
            fileName: $input['file_name'] ?? null,
        );

        if ($result['status'] !== 'success') {
            throw new ValidationException($result['message']);
        }

        return [
            'target_type' => $input['target_type'],
            'target_id' => (int) $input['target_id'],
            ...$result,
        ];
    }

    /**
     * AttachesFileToEntity attributes the stored file to whoever this returns.
     */
    protected function contextUser(): Users
    {
        return $this->user;
    }
}
