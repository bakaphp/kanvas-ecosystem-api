<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'meeting';

    public function up(): void
    {
        Schema::connection('meeting')->create('meetings', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id');
            $table->string('title');
            $table->string('status', 32)->default('awaiting_upload');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['apps_id', 'companies_id', 'users_id', 'started_at'], 'meetings_owner_started_idx');
        });

        Schema::connection('meeting')->create('meeting_recordings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meetings_id')->unique();
            $table->string('storage_disk', 64);
            $table->string('storage_key', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->json('channel_map')->nullable();
            $table->dateTime('uploaded_at');
            $table->dateTime('purged_at')->nullable();

            $table->foreign('meetings_id', 'meeting_recordings_meeting_fk')
                ->references('id')
                ->on('meetings');
        });

        Schema::connection('meeting')->create('meeting_transcripts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meetings_id');
            $table->integer('version');
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->string('provider_job_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('error_code', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('completed_at')->nullable();

            $table->unique(['meetings_id', 'version'], 'meeting_transcripts_meeting_version_unique');
            $table->foreign('meetings_id', 'meeting_transcripts_meeting_fk')
                ->references('id')
                ->on('meetings');
        });

        Schema::connection('meeting')->create('meeting_transcript_speakers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meeting_transcripts_id');
            $table->string('source_key', 64)->default('full');
            $table->string('provider_label', 64);
            $table->string('display_name')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(
                ['meeting_transcripts_id', 'source_key', 'provider_label'],
                'meeting_transcript_speakers_label_unique'
            );
            $table->unique(['meeting_transcripts_id', 'id'], 'meeting_transcript_speakers_transcript_id_unique');
            $table->foreign('meeting_transcripts_id', 'meeting_transcript_speakers_transcript_fk')
                ->references('id')
                ->on('meeting_transcripts');
        });

        Schema::connection('meeting')->create('meeting_transcript_segments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meeting_transcripts_id');
            $table->unsignedBigInteger('meeting_transcript_speakers_id')->nullable();
            $table->integer('position');
            $table->bigInteger('start_ms')->nullable();
            $table->bigInteger('end_ms')->nullable();
            $table->smallInteger('channel')->nullable();
            $table->text('text');

            $table->unique(['meeting_transcripts_id', 'position'], 'meeting_transcript_segments_position_unique');
            $table->index(
                ['meeting_transcripts_id', 'meeting_transcript_speakers_id'],
                'meeting_transcript_segments_speaker_idx'
            );
            $table->foreign('meeting_transcripts_id', 'meeting_transcript_segments_transcript_fk')
                ->references('id')
                ->on('meeting_transcripts');
            $table->foreign(
                ['meeting_transcripts_id', 'meeting_transcript_speakers_id'],
                'meeting_transcript_segments_speaker_fk'
            )
                ->references(['meeting_transcripts_id', 'id'])
                ->on('meeting_transcript_speakers');
        });

        Schema::connection('meeting')->create('meeting_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meetings_id');
            $table->unsignedBigInteger('meeting_transcripts_id')->nullable();
            $table->string('kind', 32);
            $table->longText('body');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['meetings_id', 'kind'], 'meeting_notes_meeting_kind_unique');
            $table->foreign('meetings_id', 'meeting_notes_meeting_fk')
                ->references('id')
                ->on('meetings');
            $table->foreign('meeting_transcripts_id', 'meeting_notes_transcript_fk')
                ->references('id')
                ->on('meeting_transcripts');
        });
    }

    public function down(): void
    {
        Schema::connection('meeting')->dropIfExists('meeting_notes');
        Schema::connection('meeting')->dropIfExists('meeting_transcript_segments');
        Schema::connection('meeting')->dropIfExists('meeting_transcript_speakers');
        Schema::connection('meeting')->dropIfExists('meeting_transcripts');
        Schema::connection('meeting')->dropIfExists('meeting_recordings');
        Schema::connection('meeting')->dropIfExists('meetings');
    }
};
