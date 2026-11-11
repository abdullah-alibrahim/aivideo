# AI Reels Studio — Desktop Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Separate user data from app files, complete the First Run Wizard, secure keys with OS secret stores, improve the render pipeline, polish the UI, and prepare DMG / auto-update / Windows installer tooling.

**Architecture:** A single `StudioPaths` service resolves one per-OS user-data root (`~/Library/Application Support/AI Reels Studio` on macOS, `%APPDATA%\AI Reels Studio` on Windows, `~/.local/share/AI Reels Studio` on Linux) with subfolders `database/ videos/ uploads/ backups/ settings/ keys/ logs/ tmp/`. All Laravel disks, the SQLite path, and log paths read from it; existing data is migrated by `php artisan studio:migrate-data`. Secrets move out of the DB into a `SecretStore` abstraction (macOS Keychain, Windows Credential Manager/DPAPI, encrypted-file fallback). The launcher gains a native AppleScript First Run screen with an Install/Fix flow; the web wizard gains real connectivity checks, key onboarding with auto-advance, and a live status panel. Renders gain ETA/speed reporting, quality/platform presets, segment-based resume, temp cleanup, and final compression. DMG build + updater scripts live in `scripts/`; an Inno Setup script covers Windows.

**Tech Stack:** Laravel 13 (PHP 8.4), Blade/Tailwind, Python MoviePy engine, AppleScript (osascript), hdiutil, Inno Setup, PHPUnit.

**Spec:** User backlog (9 items) in this session; priorities confirmed by user (all six tracks, in listed order).

## Global Constraints

- Data root override: env `AIVIDEO_DATA_DIR` always wins; default per-OS as above.
- Keys must never be written to `.env`, logs, or backups in the commercial build (`STUDIO_ALLOW_ENV_KEYS=0`).
- Existing tests must keep passing; new behavior gets PHPUnit feature tests.
- Follow AGENTS.md: run `vendor/bin/pint --dirty --format agent` after PHP edits; commit per task.

## Review Focus

- User with data in old locations (`~/AI Reels Studio`, `laravel-app/database/database.sqlite`, `storage/app/public`) after update — migration must not lose data (test: `MigrateStudioDataTest`).
- Secret read failure (Keychain prompt denied / entry missing) — provider calls fail with a clear message, not a crash (test: `SecretStoreTest`).
- Render crash mid-run — temp files cleaned, resume picks up completed segments (test: `RenderResumeTest`).
- First Run connectivity check without network — shows explicit failure, wizard still usable (test: `FirstRunCheckTest`).
- Windows paths with spaces (`Application Support`) — every shell call quoted.

---

## Phase 1 — Data separation

### Task 1: `App\Support\StudioPaths`

**Files:**
- Create: `laravel-app/app/Support/StudioPaths.php`
- Test: `laravel-app/tests/Feature/StudioPathsTest.php`

**Interfaces (produced):**
- `StudioPaths::root(): string`, `->database(): string` (full sqlite file path), `->videos(): string`, `->uploads(): string`, `->backups(): string`, `->settings(): string`, `->keys(): string`, `->logs(): string`, `->tmp(): string`, `->progress(): string`, `->backgrounds(): string`, `->ensure(): void`, `->subDirectory(string $name): string`.

- [ ] Write failing tests: root honors `AIVIDEO_DATA_DIR`; per-OS defaults; `ensure()` creates all subdirs.
- [ ] Implement service.
- [ ] Run `php artisan test tests/Feature/StudioPathsTest.php`; commit.

### Task 2: Wire config to StudioPaths

**Files:**
- Modify: `laravel-app/config/database.php` (sqlite path), `config/filesystems.php` (public → videos, local → uploads, links), `config/logging.php` (log path), `app/Providers/AppServiceProvider.php` (call `StudioPaths::ensure()`), `app/Console/Commands/StudioDoctor.php` (paths + data-root check).

- [ ] Update configs; run full test suite; commit.

### Task 3: Replace call sites + migrate command

**Files:**
- Modify: `app/Http/Controllers/ReelController.php` (storage_path call sites), `app/Services/ReelRenderer.php` (progress dir), `scripts/backup.sh`, `scripts/restore.sh`.
- Create: `laravel-app/app/Console/Commands/MigrateStudioData.php`
- Test: `laravel-app/tests/Feature/MigrateStudioDataTest.php`

- [ ] `studio:migrate-data` moves old sqlite + media into new root idempotently (marker file `.data-migrated`), handles `~/AI Reels Studio` legacy layout.
- [ ] Update launcher (`outputs` app + build script) to set `AIVIDEO_DATA_DIR` and keep app files under `~/Library/Application Support/AI Reels Studio/app` on macOS.
- [ ] Tests + full suite; commit.

## Phase 2 — First Run Wizard

### Task 4: Native pre-launch First Run screen (macOS)

**Files:**
- Create: `scripts/first_run_mac.sh` (osascript dialogs: progress list of checks; "Install / Fix Automatically" → Homebrew + brew install php python node ffmpeg; status live-updated; then start app).

- [ ] Script with `--check`, `--fix`, and GUI modes; shellcheck-clean; wired into app launcher before start.sh.

### Task 5: Backend check + fix endpoints

**Files:**
- Create: `laravel-app/app/Http/Controllers/FirstRunController.php`, `app/Services/FirstRunChecker.php`, `app/Services/ProviderConnectivityTester.php` (real Gemini `models.generateContent` ping + ElevenLabs `/v1/user` ping)
- Modify: `routes/web.php`, `resources/views/reels/wizard.blade.php` (check card → live status), lang files
- Test: `laravel-app/tests/Feature/FirstRunCheckTest.php`

**Interfaces:** `FirstRunChecker::run(): array{checks: list<array{name,status,detail,fixable}>, allPassed: bool}`, `ProviderConnectivityTester::testGemini(string $key): array{ok,detail}`, `::testElevenLabs(string $key): array{ok,detail}`.

- [ ] Tests with Http::fake; endpoints `/first-run`, `/api/first-run/check`, `/api/first-run/fix` (job + poll `/api/first-run/fix/status`).
- [ ] Commit.

### Task 6: Key onboarding inside First Run

- [ ] Wizard step: enter Gemini + ElevenLabs keys → store via existing controller → auto-test → auto-advance on success; `.env` key fallback gated by `STUDIO_ALLOW_ENV_KEYS` (config `studio.keys.allow_env`).
- [ ] Commit.

## Phase 3 — Keychain / Credential Manager

### Task 7: `SecretStore` abstraction

**Files:**
- Create: `app/Services/SecretStore.php` (interface), `app/Services/MacKeychainStore.php`, `app/Services/WindowsCredentialStore.php`, `app/Services/EncryptedFileStore.php` (fallback), `app/Services/SecretManager.php` (factory)
- Modify: `app/Models/ProviderApiKey.php` + migration (add `secret_ref`, make `secret` nullable), `app/Services/ProviderKeyRing.php`, `app/Http/Controllers/ProviderApiKeyController.php`
- Test: `tests/Feature/SecretStoreTest.php`, `tests/Feature/ProviderKeyRingSecretRefTest.php`

**Interfaces:** `SecretStore::store(string $ref, string $secret): void`, `::read(string $ref): ?string`, `::delete(string $ref): void`, `::deleteAll(): int`; refs shaped `provider/<uuid>`; Keychain service name `AI Reels Studio`.

- [ ] Migration + model; key ring reads via `SecretManager`; lazy migration of legacy `secret` column (read → store → null-out); "Delete all secrets" button + route; backup.sh excludes `keys/`; log redaction covers new refs.
- [ ] Tests; commit.

## Phase 4 — Render improvements

### Task 8: Progress ETA/speed + quality/platform presets

**Files:**
- Modify: `app/Services/ReelRenderer.php` (progress JSON gains elapsed/eta/fps; options), `app/Jobs/GenerateReelVideo.php`, `python_engine/smart_reels.py` (emit progress lines incl. fps), `resources/views/reels/*` status UI, `config/studio.php` (presets)
- Test: `tests/Feature/RenderProgressTest.php`

**Interfaces:** render options `quality: draft|hd|high`, `platform: tiktok|instagram|youtube|generic`; progress payload `percent, phase, fps, elapsed_seconds, eta_seconds`.

- [ ] Presets map to resolution/fps/crf/preset; ETA computed from fps; UI shows speed + ETA + preset picker; commit.

### Task 9: Resume, temp cleanup, compression, audio

**Files:**
- Modify: `python_engine/smart_reels.py` (segment rendering + `checkpoint.json` in `tmp/<reel>/`; `--resume` flag; `finally` cleanup; final `ffmpeg` compression pass; `loudnorm` + peaks JSON), `app/Services/ReelRenderer.php`, `app/Jobs/GenerateReelVideo.php`, `app/Console/Commands/RecoverStaleReels.php`
- Test: `tests/Feature/RenderResumeTest.php`

- [ ] Retry resumes from checkpoint; failure removes temp dir; final video compressed to preset bitrate; audio normalized + peaks stored; commit.

## Phase 5 — UI

### Task 10: UX batch

**Files:** Modify `resources/views/reels/*`, `app/Http/Controllers/ReelController.php`, `routes/web.php`, `lang/*`; Test: `tests/Feature/ProjectDuplicationTest.php`, `tests/Feature/ReelExportTest.php`

- [ ] Drag & drop upload; draft autosave (localStorage + PATCH draft); `POST /reels/{reel}/duplicate`; templates config + preview cards; caption/hashtags export (.txt); dashboard cards; mobile tweaks; commit.

## Phase 6 — DMG, updater, Windows installer

### Task 11: DMG build script

**Files:** Create `scripts/build-dmg.sh` (stage .app, custom icon via iconutil, Applications symlink, `hdiutil create -format UDZO`, optional `--codesign identity` + notarization stubs)

- [ ] Script ready to run by user on their Mac (hdiutil sandbox blocked here); commit.

### Task 12: Auto-update

**Files:** Create `app/Console/Commands/CheckStudioUpdates.php` (fetch manifest, compare version, download to `root()/tmp/updates`, extract over app dir preserving data dir), `app/Services/StudioVersion.php`; Modify `routes/web.php`, UI button; version file `laravel-app/config/studio.php` `version`.

- [ ] Command + UI button; tests with Http::fake; commit.

### Task 13: Windows installer

**Files:** Create `installer/windows/ai-reels-studio.iss` (Inno Setup: per-user install, Desktop/Start Menu shortcuts, uninstaller, first-run launcher, `%APPDATA%` data via env), `installer/windows/build.ps1`

- [ ] Scripts ready to build on a Windows machine; commit.
