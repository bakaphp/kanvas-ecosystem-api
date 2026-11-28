<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Services\EngagementUploadFileValidator;
use Kanvas\ActionEngine\Engagements\Services\EngagementUploadTokenService;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Services\FilesystemServices;
use Symfony\Component\HttpFoundation\Response;

class EngagementUploadController extends Controller
{
    public function store(
        string $uuid,
        Request $request,
        EngagementUploadTokenService $tokens,
        EngagementUploadFileValidator $fileValidator
    ): JsonResponse {
        $token = $request->bearerToken();
        $claims = is_string($token) ? $tokens->verify($token) : null;

        if ($claims === null) {
            return response()->json(['message' => 'Invalid or expired upload token.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! hash_equals($claims['engagement_uuid'], $uuid)) {
            return response()->json(['message' => 'Upload token does not match this engagement.'], Response::HTTP_FORBIDDEN);
        }

        $files = $this->uploadedFiles($request);
        if ($files === []) {
            return response()->json(['message' => 'At least one file is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($files as $file) {
            $fileSize = $file->getSize();
            if (! is_int($fileSize) || $fileSize > EngagementUploadFileValidator::MAX_FILE_SIZE_BYTES) {
                return response()->json(['message' => 'Each file must be 100 MB or smaller.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }

            if (! $fileValidator->isSupported($file)) {
                return response()->json(['message' => 'Unsupported file type.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $engagement = DB::connection('action_engine')->transaction(function () use ($uuid, $claims, $files): ?Engagement {
            $engagement = Engagement::query()
                ->where('uuid', $uuid)
                ->where('is_deleted', 0)
                ->lockForUpdate()
                ->first();

            if (! $engagement instanceof Engagement
                || (int) $engagement->companies_id !== $claims['company_id']
                || (int) $engagement->upload_limit !== $claims['max_files']
                || (int) $engagement->upload_count + count($files) > (int) $engagement->upload_limit
                || ! $this->isUploadWindowOpen($engagement, $claims['exp'])
            ) {
                return null;
            }

            $engagement->upload_count = (int) $engagement->upload_count + count($files);
            $engagement->saveOrFail();

            $company = $engagement->company;
            $app = $engagement->app;
            $user = $engagement->user;
            $filesystem = new FilesystemServices($app, $company);

            foreach ($files as $file) {
                // ImageOptimizerService skips files above 25 MB, so 25–100 MB images are stored unoptimized.
                // Do not fire generic file or attachment workflows for externally uploaded documents.
                $storedFile = $filesystem->upload($file, $user, runWorkflow: false);
                $attach = new AttachFilesystemAction($storedFile, $engagement);
                $attach->runWorkflow = false;
                $attach->execute('engagement-upload-' . $storedFile->getId());
            }

            return $engagement->fresh();
        });

        if ($engagement === null) {
            return response()->json(['message' => 'The upload window is closed or its file limit has been reached.'], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'uploaded' => count($files),
            'upload_count' => (int) $engagement->upload_count,
            'files' => $engagement->files()->get()->map(fn ($file) => [
                'id' => $file->getId(),
                'name' => $file->name,
                'url' => $file->url,
            ])->values(),
        ], Response::HTTP_CREATED);
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploadedFiles(Request $request): array
    {
        $input = $request->file('files', $request->file('file'));
        $files = is_array($input) ? $input : [$input];

        return array_values(array_filter($files, static fn ($file): bool => $file instanceof UploadedFile));
    }

    private function isUploadWindowOpen(Engagement $engagement, int $tokenExpiresAt): bool
    {
        if ($engagement->upload_expires_at === null || $engagement->isComplete()) {
            return false;
        }

        $expiresAt = Carbon::parse($engagement->upload_expires_at);
        if ($expiresAt->isPast() || $expiresAt->getTimestamp() !== $tokenExpiresAt) {
            return false;
        }

        $stage = strtolower((string) $engagement->stage?->slug);
        $messageStatus = strtolower((string) ($engagement->message?->getMessage()['status'] ?? ''));
        $closedStatuses = ['completed', 'submitted', 'declined'];

        return ! in_array($stage, $closedStatuses, true)
            && ! in_array($messageStatus, $closedStatuses, true);
    }
}
