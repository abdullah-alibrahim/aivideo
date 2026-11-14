# Hostinger Shared Hosting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver an invite-only, multi-user Laravel web studio whose PHP renderer runs FFmpeg on the same Hostinger shared-hosting account, while keeping the existing Python renderer for local desktop launches.

**Architecture:** Keep render jobs asynchronous and inject a `RenderEngine` selected by `AIVIDEO_RENDER_DRIVER`; Hostinger defaults to the PHP/FFmpeg implementation, while desktop launchers explicitly select the existing Python implementation. Add Laravel session authentication, one-time administrator-issued invitations, owner-scoped records, and private media routes/storage. Use Laravel's existing Symfony Process dependency to run FFmpeg safely without adding a video package unless a tested compatibility need justifies it.

**Tech Stack:** Laravel 13, PHP 8.3+, Blade, PHPUnit, MySQL on Hostinger, existing database queue and Symfony Process, local FFmpeg/FFprobe, existing Python/MoviePy desktop engine.

**Spec:** [Hostinger Shared Hosting Design](../specs/2026-11-12-hostinger-shared-hosting-design.md)

## Global Constraints

- Implement web rendering in PHP/Laravel, using an FFmpeg executable available on the same Hostinger account; do not require Python for hosted web rendering.
- Keep the existing Python/MoviePy renderer and desktop workflow working unchanged.
- Authenticate members and administrators. Accounts are invite-only; there is no public registration.
- Keep provider API keys and system-wide settings administrator-only.
- Restrict reel lists, details, status, cancellation, downloads, streams, and source uploads to the owning user; administrators may manage the service.
- Start with a low-volume shared-hosting profile: at most one active render globally and conservative, configurable per-member/render/upload limits.
- Before claiming production readiness, verify the actual account supports CLI/Artisan, local FFmpeg/FFprobe with required codecs and filters, persistent private storage, outbound provider calls, a supported database, and scheduled jobs within its limits.
- If a hard Hostinger requirement is unavailable, stop and report the blocker; do not switch to a remote renderer, synchronous web-request rendering, or a feature-reduced renderer.
- Preserve existing local SQLite support for development and tests.

## Review Focus

- Missing `proc_open`, FFmpeg, H.264/AAC encoders, Arabic text shaping, subtitle filters, or execution permission — the app reports a blocking readiness error and does not enqueue a render (tests: `StudioDoctorTest::test_missing_or_incomplete_ffmpeg_is_a_blocker`, `FfmpegReelRendererTest::test_arabic_text_and_subtitle_capabilities_are_required`).
- Expired, replayed, or guessed invitation tokens and login floods — only a valid unused invitation creates an account, and login/invite endpoints are throttled (tests: `InviteOnlyAuthenticationTest::test_expired_or_accepted_invitation_cannot_create_an_account`, `test_login_is_rate_limited`).
- Another user's reel, collection, progress, source, thumbnail, subtitle, stream, or ZIP identifier — access is denied even when the record exists (tests: `ReelOwnershipTest::test_member_cannot_access_another_users_reel_or_collection_media`).
- User-controlled filenames/text containing shell metacharacters, Unicode, or newlines — FFmpeg arguments remain an argv array and text is passed via safe temporary files, never interpolated into a shell command (test: `FfmpegReelRendererTest::test_user_text_and_paths_are_not_interpreted_as_shell_commands`).
- Cancellation, timeout, or FFmpeg failure during a render — no partial output is exposed, progress/state is explicit, and the worker releases its single-render lock (tests: `FfmpegReelRendererTest::test_failed_or_cancelled_render_cleans_partial_outputs`, `RenderQueueTest::test_cancelled_render_job_does_not_start_or_publish_output`).

## File Map

| Area | Files and responsibility |
|---|---|
| Render abstraction | `laravel-app/app/Services/RenderEngine.php` defines render/preview contracts; `PythonReelRenderer.php` retains current local behavior; `FfmpegReelRenderer.php` implements the hosted pipeline; `FfmpegCommandBuilder.php` builds argument-safe commands. |
| Render content and runtime | `laravel-app/app/Services/ReelContentGenerator.php` owns Gemini script generation; `ElevenLabsVoiceoverService.php` owns voice requests; `config/studio.php`, `StudioDoctor.php`, `FirstRunChecker.php`, and `FirstRunFixer.php` select/check the active engine. |
| Accounts and invitations | `laravel-app/app/Models/User.php`, `StudioInvitation.php`, migrations, auth/admin controllers, middleware, `routes/web.php`, bilingual auth/admin Blade views, and feature tests. |
| Tenant/media isolation | `Reel.php`, `ReelCollection.php`, ownership migration, `ReelController.php`, `GenerateReelVideo.php`, `PruneReels.php`, `config/filesystems.php`, and reel views. |
| Deployment and launch | `.env.example`, `routes/console.php`, `scripts/deploy-check.sh`, root `README.md`, and `laravel-app/README.md`; desktop launch scripts select Python explicitly. |

## Task 1: Establish renderer selection and the Hostinger capability gate

**Files:**
- Create: `laravel-app/app/Services/RenderEngine.php`
- Create: `laravel-app/app/Services/PythonReelRenderer.php`
- Create: `laravel-app/app/Services/FfmpegCapabilities.php`
- Modify: `laravel-app/app/Services/ReelRenderer.php` (move existing Python implementation to `PythonReelRenderer`)
- Modify: `laravel-app/app/Jobs/GenerateReelVideo.php` (depend on `RenderEngine`)
- Modify: `laravel-app/config/studio.php`, `laravel-app/.env.example`, `laravel-app/app/Providers/AppServiceProvider.php`
- Modify: `laravel-app/app/Console/Commands/StudioDoctor.php`, `laravel-app/app/Services/FirstRunChecker.php`
- Modify: `start.sh`, `start.ps1`, `scripts/mac-app-launcher.sh` (select Python for desktop)
- Test: `laravel-app/tests/Feature/RenderDriverTest.php`, `laravel-app/tests/Feature/StudioDoctorTest.php`, `laravel-app/tests/Feature/FirstRunCheckTest.php`, `laravel-app/tests/Feature/RenderQueueTest.php`

**Interfaces:**
- Produces `RenderEngine::render(Reel $reel, array $inputPaths, ?string $musicPath, array $options, string $outputRelativePath): array`.
- Produces `RenderEngine::renderPreview(array $options, string $outputPath): void`.
- Produces `FfmpegCapabilities::inspect(): array{ffmpeg:string|null,ffprobe:string|null,version:string|null,proc_open:bool,font_readable:bool,h264:bool,aac:bool,drawtext:bool,subtitles:bool,text_shaping:bool}`.
- `RenderEngine` is bound from `config('studio.engine.driver')`; desktop launchers set `AIVIDEO_RENDER_DRIVER=python`; production default becomes `ffmpeg` in Task 3.

- [ ] Write `RenderDriverTest::test_desktop_driver_uses_python_renderer` and `StudioDoctorTest::test_missing_or_incomplete_ffmpeg_is_a_blocker`; assert the selected renderer and exact missing capability are reported.
- [ ] Run `cd laravel-app && php artisan test --compact tests/Feature/RenderDriverTest.php tests/Feature/StudioDoctorTest.php`; confirm the new tests fail for missing bindings/checks.
- [ ] Extract the existing Python command, preview, progress, cancellation, and result parsing into `PythonReelRenderer`; add the `RenderEngine` interface and bind the selected engine in `AppServiceProvider`.
- [ ] Add `AIVIDEO_RENDER_DRIVER`, `AIVIDEO_FFMPEG_BIN`, and `AIVIDEO_FFPROBE_BIN` configuration; preserve the existing Python paths and Arabic font configuration for desktop.
- [ ] Implement `FfmpegCapabilities::inspect()` with Symfony Process argv calls. Require `proc_open`, runnable FFmpeg/FFprobe, a readable configured Arabic font, an H.264/AAC path, `drawtext` with text shaping, and subtitle support; never report success when a required probe fails.
- [ ] Update `studio:doctor` and the First Run checks/fixes to validate the configured driver instead of assuming Python is required by the hosted app.
- [ ] Set `AIVIDEO_RENDER_DRIVER=python` in macOS/Linux/Windows desktop launch flows; run `php artisan test --compact tests/Feature/RenderDriverTest.php tests/Feature/StudioDoctorTest.php tests/Feature/FirstRunCheckTest.php tests/Feature/RenderQueueTest.php` and commit.

## Task 2: Move hosted script and voice generation into Laravel

**Files:**
- Create: `laravel-app/app/Services/ReelContentGenerator.php`
- Create: `laravel-app/app/Services/ElevenLabsVoiceoverService.php`
- Modify: `laravel-app/app/Services/GeminiContentService.php` only if a shared response/metadata helper is needed
- Modify: `laravel-app/app/Http/Controllers/ReelController.php` (`store`, `retry`, and scenario/render options)
- Modify: `laravel-app/app/Jobs/GenerateReelVideo.php` (keep provider secrets out of serialized jobs)
- Modify: `laravel-app/resources/views/reels/index.blade.php`, `laravel-app/resources/views/reels/wizard.blade.php` (remove per-request Gemini-key fields)
- Test: `laravel-app/tests/Feature/ReelContentGenerationTest.php`, `laravel-app/tests/Feature/ElevenLabsVoiceoverTest.php`, `laravel-app/tests/Feature/RenderQueueTest.php`

**Interfaces:**
- Produces `ReelContentGenerator::generate(Reel $reel, array $options): array{script:string,headline:string,points:list<string>,caption:string,hashtags:string,content_source:string,ai_model:string|null}`.
- Produces `ElevenLabsVoiceoverService::generate(string $text, string $language, string $tone, string $outputPath): void`.
- Consumers use the existing `GeminiContentService` and `ProviderKeyRing`; no API key is accepted from a member request or persisted in a queued job.

- [ ] Write tests proving manual content skips Gemini, AI content returns normalized headline/scenes/caption/hashtags/model metadata, and the job payload/options contain no provider secret.
- [ ] Write HTTP-faked ElevenLabs tests for successful audio output, missing keys, rate-limit cooldown, and non-retryable provider errors.
- [ ] Run `cd laravel-app && php artisan test --compact tests/Feature/ReelContentGenerationTest.php tests/Feature/ElevenLabsVoiceoverTest.php`; confirm failures before implementation.
- [ ] Port the existing `generate_ai_package` request/response contract to `ReelContentGenerator`, using active administrator-configured keys from `ProviderKeyRing`; preserve manual-content and batch/variant directives.
- [ ] Implement ElevenLabs audio generation using Laravel HTTP, active keys/voice IDs, bounded timeouts, binary response validation, and provider health reporting.
- [ ] Remove `gemini_keys` from `ReelController::store` validation/options and `retry` dispatch; remove the corresponding form fields. Keep provider setup solely in administrator routes.
- [ ] Run `cd laravel-app && php artisan test --compact tests/Feature/ReelContentGenerationTest.php tests/Feature/ElevenLabsVoiceoverTest.php tests/Feature/RenderQueueTest.php tests/Feature/ReelWorkflowTest.php` and commit.

## Task 3: Implement PHP/FFmpeg hosted rendering and preview

**Files:**
- Create: `laravel-app/app/Services/FfmpegReelRenderer.php`
- Create: `laravel-app/app/Services/FfmpegCommandBuilder.php`
- Create: `laravel-app/app/Services/FfmpegProgressParser.php`
- Modify: `laravel-app/app/Http/Controllers/ReelController.php` (`preview`)
- Modify: `laravel-app/config/studio.php` (make `ffmpeg` the web default)
- Modify: `laravel-app/lang/ar/studio.php`, `laravel-app/lang/en/studio.php`
- Test: `laravel-app/tests/Unit/Services/FfmpegCommandBuilderTest.php`, `laravel-app/tests/Feature/FfmpegReelRendererTest.php`, `laravel-app/tests/Feature/ReelWorkflowTest.php`, `laravel-app/tests/Feature/RenderQueueTest.php`

**Interfaces:**
- `FfmpegReelRenderer` implements `RenderEngine::render(...)` and `RenderEngine::renderPreview(...)`.
- `FfmpegCommandBuilder::build(Reel $reel, array $inputPaths, ?string $musicPath, array $options, string $outputPath, ?string $voicePath, ?string $subtitlePath): array` returns an argv list; no shell command string.
- `FfmpegProgressParser::parse(string $output): array{stage:string,progress:int}` maps FFmpeg `-progress pipe:1` output to the existing progress stages.
- Successful render metadata retains the shape consumed by `GenerateReelVideo::markCompleted`: script, caption, hashtags, content source, model, duration, thumbnail path, and subtitle path.

- [ ] Write command-builder tests for every supported aspect ratio (9:16, 1:1, 16:9), quality (720/1080), templates, motion, transitions, captions, audio, SRT, and paths/text containing spaces, quotes, Unicode, and shell metacharacters.
- [ ] Write render tests for a valid MP4/thumbnail/SRT result, Arabic shaping, missing inputs, FFmpeg failure, timeout, and cancellation cleanup.
- [ ] Run `cd laravel-app && php artisan test --compact tests/Unit/Services/FfmpegCommandBuilderTest.php tests/Feature/FfmpegReelRendererTest.php`; confirm the tests fail before implementation.
- [ ] Build argument-safe FFmpeg filter graphs using text files for user content, the configured Arabic font, `drawtext` text shaping, video scale/crop/motion, existing templates/transitions, burned-in captions, audio mixing/ducking, H.264/AAC export, thumbnail extraction, and SRT generation. Escape FFmpeg filtergraph syntax separately from argv boundaries; never interpolate raw member text or paths into a filter string.
- [ ] Reuse `ReelContentGenerator` and `ElevenLabsVoiceoverService` from Task 2; retain the current `RenderFailedException` stages, timeout handling, one-render lock, cancellation polling, progress semantics, and cleanup of partial files.
- [ ] Route `ReelController::preview` through `RenderEngine::renderPreview`; return a private/temporary preview URL rather than a public storage URL.
- [ ] Make `ffmpeg` the default driver for web deployments while desktop launchers continue selecting Python. Run targeted Laravel tests, then render short Arabic and English fixtures and verify with FFprobe that output codec, dimensions, duration, thumbnail, and subtitles are correct; commit.

## Task 4: Add invite-only login and administrator account management

**Files:**
- Create: `laravel-app/app/Models/StudioInvitation.php`
- Create: `laravel-app/app/Http/Controllers/Auth/AuthenticatedSessionController.php`
- Create: `laravel-app/app/Http/Controllers/StudioInvitationController.php`
- Create: `laravel-app/app/Http/Controllers/Admin/StudioInvitationController.php`
- Create: `laravel-app/app/Http/Middleware/EnsureStudioAdministrator.php`
- Create: `laravel-app/app/Console/Commands/CreateStudioAdministrator.php`
- Create: `laravel-app/resources/views/auth/login.blade.php`, `laravel-app/resources/views/auth/accept-invitation.blade.php`, `laravel-app/resources/views/admin/invitations/index.blade.php`
- Create: `laravel-app/lang/ar/auth.php`, `laravel-app/lang/en/auth.php`
- Modify: `laravel-app/app/Models/User.php`, `laravel-app/database/factories/UserFactory.php`, `laravel-app/routes/web.php`, `laravel-app/bootstrap/app.php`
- Modify: `laravel-app/app/Http/Controllers/FirstRunController.php`, `laravel-app/resources/views/first-run/index.blade.php`
- Delete: `laravel-app/app/Services/FirstRunFixer.php` (move migrations/key/storage/build operations to CLI deployment steps)
- Delete: `laravel-app/app/Http/Middleware/StudioAccess.php`
- Modify: `laravel-app/config/studio.php`, `laravel-app/app/Console/Commands/StudioDoctor.php` (replace legacy Basic Auth configuration/safety checks with user/session authorization)
- Modify: `start.sh`, `start.ps1` (securely bootstrap a local administrator on first run)
- Create: `laravel-app/database/migrations/*_add_admin_role_to_users_table.php`, `laravel-app/database/migrations/*_create_studio_invitations_table.php`
- Test: `laravel-app/tests/Feature/InviteOnlyAuthenticationTest.php`

**Interfaces:**
- Administrator endpoint `GET/POST /admin/invitations` lists and creates one-time invitations; acceptance endpoints `GET/POST /invitations/{token}` validate and consume a hashed, expiring token.
- `studio:make-admin {email?} {--if-missing}` provisions the first administrator from CLI; it never creates an administrator through public registration. Without an email, it prompts only on an interactive local launch.
- `EnsureStudioAdministrator` denies non-admin users with HTTP 403.

- [ ] Write tests for login/logout, no public registration route, valid invitation acceptance, expired/replayed/invalid invitation rejection, admin-only invitations/settings/first-run, throttled login, and CLI bootstrap.
- [ ] Run `cd laravel-app && php artisan test --compact tests/Feature/InviteOnlyAuthenticationTest.php`; confirm the new account and access tests fail.
- [ ] Add `is_admin` to `User` using a non-mass-assignable default and add an invitation table storing unique token hashes, email, creator, expiry, acceptance timestamp, and timestamps.
- [ ] Implement session login with Laravel's auth guard, validation, regeneration/invalidation of sessions, CSRF, and rate limiting; do not add a public registration route.
- [ ] Implement admin-created one-time invitation links displayed for copy in the admin page; hash tokens at rest, expire them, consume once, and let the invitee set their own password.
- [ ] Protect all studio routes with `auth`; protect provider keys, read-only first-run checks/key setup, system analytics, and invitation administration with administrator middleware. Remove the web `/api/first-run/fix` action that runs Artisan/npm; keep migrations, APP_KEY, storage-link, and asset-build operations in CLI deployment/local setup.
- [ ] Add the CLI administrator bootstrap, bilingual auth/admin copy, and logout/navigation controls. Remove the `studio.access` alias and `STUDIO_AUTH_*` settings after all sensitive routes require session authentication and the administrator middleware.
- [ ] Preserve desktop onboarding by having local launchers run the interactive admin bootstrap only when no administrator exists; production uses the documented SSH/CLI bootstrap.
- [ ] Run `cd laravel-app && php artisan test --compact tests/Feature/InviteOnlyAuthenticationTest.php tests/Feature/ProviderApiKeyManagementTest.php tests/Feature/FirstRunCheckTest.php` and commit.

## Task 5: Enforce ownership and make generated media private

**Files:**
- Create: `laravel-app/database/migrations/*_add_user_ownership_to_reels_and_collections.php`
- Modify: `laravel-app/app/Models/User.php`, `laravel-app/app/Models/Reel.php`, `laravel-app/app/Models/ReelCollection.php`
- Modify: `laravel-app/config/filesystems.php`
- Modify: `laravel-app/app/Http/Controllers/ReelController.php`, `laravel-app/app/Http/Controllers/AnalyticsController.php`
- Modify: `laravel-app/app/Jobs/GenerateReelVideo.php`, `laravel-app/app/Console/Commands/PruneReels.php`
- Modify: `laravel-app/resources/views/reels/index.blade.php`
- Test: `laravel-app/tests/Feature/ReelOwnershipTest.php`, `laravel-app/tests/Feature/PrivateReelMediaTest.php`, `laravel-app/tests/Feature/ReelWorkflowTest.php`, `laravel-app/tests/Feature/RenderQueueTest.php`

**Interfaces:**
- New reel and collection records receive the authenticated `user_id`; old rows remain nullable for migration compatibility and are visible only to administrators.
- Add `User::reels(): HasMany` and `User::reelCollections(): HasMany`; add matching `Reel::user()` and `ReelCollection::user()` relations.
- Private media routes `reels.stream`, `reels.thumbnail`, `reels.subtitle`, `reels.preview-media`, and `reels.collections.download` provide owner/admin-authorized range streaming, thumbnail/SRT retrieval, temporary preview retrieval, and collection ZIP download.

- [ ] Write tests proving one member cannot access another's reel through list/detail/status/cancel/retry/delete, another's collection ZIP, or video/thumbnail/SRT media; include existing byte-range stream assertions for the owner.
- [ ] Write tests proving generated MP4s, thumbnails, subtitles, previews, and uploaded inputs are not directly reachable from a public storage URL; assert deletion/pruning preserves files referenced by another reel.
- [ ] Run `cd laravel-app && php artisan test --compact tests/Feature/ReelOwnershipTest.php tests/Feature/PrivateReelMediaTest.php`; confirm unauthorized access and public-path tests fail.
- [ ] Add nullable foreign keys for `reels.user_id` and `reel_collections.user_id` so existing local databases migrate safely; always set owners for newly created reels/collections and retain admin-only access to legacy ownerless rows.
- [ ] Scope `ReelController` lists, filters, stats, render creation, status, cancel, retry, delete, stream, collection export, and AJAX operations to the authenticated user or an administrator. Scope service-wide analytics to administrators.
- [ ] Add a private media disk rooted outside `public_html`; retain a public storage link only to `StudioPaths::backgrounds()` so backgrounds still load but reels/previews are no longer exposed by the current broad storage symlink. Update `backgroundCatalog()` URLs accordingly.
- [ ] Move new render outputs, thumbnails, subtitles, and previews to the private disk and serve them through authenticated routes; update the Blade player/download links and `preview` JSON URL.
- [ ] Update `storage:link --force` behavior and verify it replaces an existing broad `public/storage -> StudioPaths::videos()` link with the backgrounds-only link. Existing output files stay in the same private data root and must no longer be reachable under `/storage/reels`, `/storage/thumbnails`, `/storage/subtitles`, or `/storage/previews`.
- [ ] Update job completion/failure, retries, deletion, and `reels:prune` to use the private media disk; preserve shared-file reference checks.
- [ ] Add configurable `STUDIO_MAX_QUEUED_REELS_PER_USER=5`, authenticated-user rate-limit keys, and enforce the projected count for batch/variant requests while preserving global render concurrency one; test that a six-reel projected queue is rejected without creating partial records.
- [ ] Run `cd laravel-app && php artisan test --compact tests/Feature/ReelOwnershipTest.php tests/Feature/PrivateReelMediaTest.php tests/Feature/ReelWorkflowTest.php tests/Feature/RenderQueueTest.php` and commit.

## Task 6: Prepare and document Hostinger deployment

**Files:**
- Modify: `laravel-app/routes/console.php` (bounded scheduled queue worker)
- Modify: `laravel-app/app/Console/Commands/StudioDoctor.php`, `laravel-app/app/Services/FirstRunChecker.php`
- Modify: `laravel-app/.env.example`, `scripts/deploy-check.sh`
- Modify: `README.md`, `laravel-app/README.md`
- Test: `laravel-app/tests/Feature/SharedHostingReadinessTest.php`, `laravel-app/tests/Feature/RenderQueueTest.php`

**Interfaces:**
- A scheduled queue event processes at most one job per invocation and does not overlap; Hostinger Cron runs `php artisan schedule:run` at the supported cadence.
- `studio:doctor` and the deployment check report the configured database, renderer, FFmpeg/FFprobe capabilities, font, storage permissions/free space, queue, provider keys, and production safety without requiring Python for the web driver.

- [ ] Write `SharedHostingReadinessTest::test_web_readiness_does_not_require_python` and `RenderQueueTest::test_scheduled_worker_is_single_job_and_non_overlapping`.
- [ ] Run `cd laravel-app && php artisan test --compact tests/Feature/SharedHostingReadinessTest.php tests/Feature/RenderQueueTest.php`; confirm the current Python-only readiness and unbounded worker assumptions fail.
- [ ] Schedule a non-overlapping `queue:work --stop-when-empty --max-jobs=1` invocation with the configured timeout; preserve stale recovery and pruning schedules.
- [ ] Set and document production defaults for MySQL, database queue, `AIVIDEO_RENDER_DRIVER=ffmpeg`, FFmpeg/FFprobe paths, Arabic font, HTTPS, `APP_DEBUG=false`, safe data root, private media, and one-render concurrency.
- [ ] Update `studio:doctor`, `scripts/deploy-check.sh`, `laravel-app/README.md`, and the root README with Hostinger's Laravel `public` document root, Composer/build/migration commands, hPanel Cron setup, permissions, backup/restore, first-admin bootstrap, and explicit FFmpeg/CLI/cron resource gates. Keep Python installation instructions only for desktop/local mode.
- [ ] Run `cd laravel-app && php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `npm run build`, and `../venv/bin/python3 -m unittest ../python_engine/test_smart_reels.py -v`.
- [ ] Run `./scripts/deploy-check.sh`, then execute `php artisan studio:doctor` and a real Arabic/English queued render on the actual Hostinger account. If the account blocks FFmpeg, required filters/codecs, storage, or a scheduled render within its limits, report the exact failed check and stop without claiming deployment readiness; commit only after the configured-account smoke test passes.

## Spec Coverage

- Invite-only accounts, administrator-only provider/system settings, and initial admin bootstrap: Task 4.
- Per-user reels/collections, private inputs/outputs, authenticated range streaming/downloads, and admin analytics: Task 5.
- PHP/Laravel web rendering, local FFmpeg, Arabic text/captions/transitions/audio, and Python desktop compatibility: Tasks 1–3.
- Asynchronous bounded queue, one concurrent render, private persistent storage, MySQL, Cron, readiness checks, and Hostinger instructions: Tasks 1, 5, and 6.
- Hard stop on unavailable Hostinger capabilities and a successful real render before claiming readiness: Task 6.
