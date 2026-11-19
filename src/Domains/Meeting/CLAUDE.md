# Meeting — Recorded Meetings, Transcripts and Notes

A meeting recorded by the desktop (Electron) client: one audio recording, N transcript versions
(speakers + segments) and one note per kind. Own `meeting` connection
(`database/migrations/Meeting/`, `composer migrate-meeting`, `DB_MEETING_*`).

## Rules worth knowing

- **A meeting is private to its owner.** Tenancy (`apps_id`, `companies_id`, `users_id`) lives only on
  `meetings`; recordings, transcripts, speakers, segments and notes inherit it through the meeting.
  Look rows up with `Meeting::getByIdFromOwner()` / `MeetingTranscript::getByIdFromOwner()` /
  `MeetingTranscriptSpeaker::getByIdFromOwner()`, never by bare id.
- **A meeting can be assigned to one CRM person** (`meetings.people_id`, nullable; `people_id` on
  `createMeeting` / `updateMeeting`, `null` unassigns). People live on the `crm` connection, so it is
  an id the DTO resolves inside the meeting's company and app, not a foreign key. It is a label: the
  meeting stays private to its owner and nothing is written to the person.
- **No soft delete.** These tables have no `is_deleted`, so the `KanvasModelTrait` lookup helpers
  (`getById`, `getByIdFromCompanyApp`, ...) fail on them — they all call `notDeleted()`.
- **Client retries are answered, not duplicated.** `createMeeting` is idempotent on the client-made
  `uuid`; `uploadMeetingRecording` on the file's `sha256`.
- **The server stores the audio.** `uploadMeetingRecording` (multipart) puts the file on the default
  disk under `meetings/{app}/{company}/{meeting uuid}/{sha256}.webm` and attaches it; it refuses bytes
  that do not hash to the `sha256` the client sent. The request is capped at 512 MB (Octane's
  `package_max_length`). `AttachMeetingRecordingAction` is internal: it trusts the disk and key it is
  given, so no mutation takes them from a client.
- **Transcript versions are numbered under a lock on the meeting row** (`CreateMeetingTranscriptAction`).
- **Speakers outlive a re-store.** `StoreMeetingTranscriptSegmentsAction` replaces segments but matches
  speakers by `(source_key, provider_label)`, so a `display_name` the user confirmed is kept.
- **The meeting status follows the pipeline**: recording attached → `uploaded`, transcript created →
  `transcribing`, transcript completed/failed → `completed` / `failed`. Only the newest transcript
  version moves it, so a late answer for a superseded run changes that transcript and nothing else.
- A transcript needs a recording, and storing segments needs at least one.
- `meeting_notes.body` is `longText` (a long enhanced note passes 64 KB); segment `text` is capped at
  16000 characters by the schema so it always fits its `TEXT` column.
- `storage_disk` / `storage_key` are not exposed on the GraphQL type. Millisecond and byte fields are
  `Float` in the schema because a GraphQL `Int` is 32-bit.

## Transcription

`UploadMeetingRecordingAction` queues `TranscribeMeetingJob` (queue `meeting-transcripts`, one attempt)
for every new recording; `kanvas:meeting:transcribe {meeting_id}` runs the same action by hand and
writes a new version. Both end by queueing the notes.

```
TranscribeMeetingAction
  ├ TranscriptAgentService::handlerFor()   which model, on whose key
  ├ CreateMeetingTranscriptAction          version N, meeting → transcribing
  ├ RecordingAudioService::chunks()        ffmpeg: mono 16 kHz Opus, 25-minute chunks
  ├ TranscriptAgent::transcribe() per chunk
  └ StoreMeetingTranscriptSegmentsAction   or a failed transcript with an error code
```

- **The agent type is "Transcript"** (`TranscriptAgent`, Neuron, synced by
  `kanvas:intelligence:sync-agent-types`). It runs with no Agent row: `gemini-3.5-transcribe` on the
  app's Gemini key.
- **A company's Transcript agent decides the provider.** When the company has an agent of this type,
  whatever it resolves to, the way any agent's provider resolves (its LLM config, then
  `config.llm_provider` / `config.model`), is what runs; only a Gemini agent that names no model falls
  back to `gemini-3.5-transcribe`. `config.transcription.{diarization,word_timestamps}` tune the
  transcribe model. The oldest agent of the type wins when a company has several.
- **The provider must take audio** (`AttachmentDescriptionService::providerHearsAudio()`: Gemini,
  Mistral, Bedrock). Anthropic and OpenAI chat drop the audio block, so an agent pointed at one fails
  the transcript with `provider_cannot_hear_audio` before any call is made, instead of letting a model
  that heard nothing write one.
- **`gemini-*-transcribe` is not a chat model.** It rejects system instructions and JSON mode, ignores
  the prompt and answers `audioTranscription` parts, one per speaker turn, so it has its own provider
  (`KanvasGeminiTranscribe`). Any other model is a chat model held to the agent's instructions; both
  reply with the same `{"segments": [...]}` JSON. Measured 2026-11-15 on the same recording:
  `gemini-3.8-flash` as a chat model returned the speakers and per-turn timestamps.
- **Speakers or timestamps, not both, by default.** With word timestamps on, the model returned a
  two-speaker recording as one speaker (measured 2026-11-15), so diarization is on and timestamps are
  off: segments carry a speaker and null `start_ms` / `end_ms`.
- **25-minute chunks.** With diarization the model takes 30 minutes per request and silently drops the
  rest. A speaker label only means something inside one request, so each chunk is its own `source_key`
  (`part-1`, `part-2`; `full` for a single chunk): the same person is a different speaker row in each
  chunk until someone names them.
- **Every run ends as a transcript row**, and the meeting goes to `failed`, never stays
  `transcribing`: `provider_not_configured` (no key), `provider_cannot_hear_audio`, `no_speech` (the
  transcribe model answers silence with a candidate that has no parts), `transcription_failed`, and
  `transcription_interrupted`, written by the job's `failed()` for a worker killed mid-run (the queue
  fails the redelivery without running it). All but `no_speech` are reported.
- **One queued job per meeting** (`ShouldBeUniqueUntilProcessing`). The upload dispatches for a
  recording it created, and again on a retry of the same file while the meeting has no transcript at
  all, which is the way back for a job that was never queued; the lock drops the duplicates.
- **The audio is untrusted bytes.** `mime_type` is the client's word, so ffmpeg is pinned to the
  Matroska/WebM demuxer and to the `file` protocol.
- A chat model on the company agent keeps the 220 s request timeout of `AgentProviderService`; only
  the transcribe provider gets 900 s.
- On the `sync` queue connection the job runs after the response, inside Octane's 120 s
  `max_execution_time`: a long recording there needs the command.

## Enhanced notes

A transcript `TranscribeMeetingAction` completes queues `GenerateMeetingNotesJob` (queue
`meeting-notes`, three attempts). Segments stored through the `storeMeetingTranscriptSegments`
mutation do not: only the server's own run does.

- **The agent type is "Meeting Notes"** (`MeetingNotesAgent`, Neuron). No Agent row: the app's
  `GEMINI_MODEL` (else `gemini-3.8-flash`) on the app's Gemini key. A company's own agent of this type
  runs on that agent's provider instead.
- **One enhanced note per meeting**, rewritten in place by a later transcript version
  (`SaveMeetingNoteAction`). The manual note is never touched; it goes into the brief as what the
  person cared about.
- **Speakers reach the agent as the user named them**, else numbered by speaker row in order of
  appearance, so the same voice in two chunks of a long recording is two numbers and the prompt says
  not to merge them.
- **Only the newest transcript version writes the note.** A late or retried job for an older
  version answers null instead of putting the older text back.
- **A failed note changes nothing else**: there is no note status, the transcript and the meeting stay
  `completed`, and the job's failure is what reports it.
- Both agents share `Agents/Concerns/RunsSingleTurn`: one message in, one reply out, no tools, memory
  or stored history, provider handed in by the caller.

One worker, `meeting-queue`, consumes `meeting-transcripts,meeting-notes` in the three compose files:
the volume is one job of each per recorded meeting.

## Not built yet

A direct-to-storage (presigned) upload for recordings over 512 MB, a GraphQL mutation to re-run a
transcript or its notes, usage/cost recording for either agent, audio purge
(`purged_at`) and deleting a meeting. The actions here are what those jobs should call.
