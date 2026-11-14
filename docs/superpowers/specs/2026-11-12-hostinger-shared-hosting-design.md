# Hostinger Shared Hosting Design

## Intent

Deploy AI Reels Studio as a public, invite-only web application on the user's existing Hostinger shared hosting account. A signed-in user can create and manage their own generated videos. Implement web rendering in PHP/Laravel using FFmpeg installed on that same Hostinger account. Do not send rendering to an external server or rendering service. Keep the existing Python renderer for the desktop application only.

## Why

The current web application delegates rendering to a Python/MoviePy engine that is also used by the desktop application. The owner wants the hosted web application to render through PHP/Laravel rather than depend on the Python engine, while keeping provider credentials and operational settings private. The existing Python-based desktop workflow must continue to work.

## Goals

- Make the Laravel web app deployable with Hostinger's supported PHP, database, storage, and web-root configuration.
- Implement the web renderer in PHP/Laravel, using an FFmpeg executable available on the same Hostinger account; do not require Python for hosted web rendering.
- Preserve the current web renderer's user-facing features, including Arabic text/layout, selected video/background composition, music/voice, captions/subtitles, supported transitions, and output formats.
- Authenticate members and administrators. Accounts are invite-only; there is no public registration.
- Keep provider API keys and system-wide settings administrator-only.
- Restrict reel lists, details, status, cancellation, downloads, streams, and source uploads to the owning user; administrators may manage the service.
- Process PHP/Laravel render jobs using only mechanisms available on the shared plan, such as a scheduled CLI worker if supported.
- Add practical per-account and upload limits, while retaining request throttling, CSRF protections, and secure production defaults.
- Start with a low-volume shared-hosting profile: at most one active render globally and conservative, configurable per-member/render/upload limits.
- Provide clear deployment and operations instructions for the chosen Hostinger setup.
- Keep the existing Python/MoviePy renderer and desktop workflow working unchanged.

## Non-goals

- Public self-service registration or anonymous generation.
- Moving rendering or media storage to a third-party server or rendering API.
- Replacing or removing the existing Python desktop renderer or redesigning the product.
- Treating a successful upload as proof that the Hostinger account can run production renders.

## Constraints and feasibility gate

The existing web render path invokes Python 3.11, MoviePy, and FFmpeg in a long-running queue job. The replacement web renderer must not invoke Python, but video encoding still requires an FFmpeg executable; a PHP/Laravel package can wrap FFmpeg but cannot replace the codec engine. A standard web request is not an appropriate render process. Before implementation is considered deployable, verify the actual Hostinger plan supports the required PHP CLI/Artisan commands, an FFmpeg/FFprobe build with the needed codecs, writable persistent storage outside the public web root, outbound provider API access, MySQL (or another supported production database), and scheduled jobs capable of draining the queue without exceeding execution limits. Python remains a local desktop requirement only.

The application must use a supported scheduled worker or another mechanism available on this same shared account. Do not assume a continuously running queue worker or supervisor service is available. If any hard requirement is unavailable, stop and report the exact blocker and evidence; do not silently substitute external rendering, synchronous web-request rendering, or a feature-reduced renderer.

## Agreed product behavior

- Visitors must sign in before accessing the studio or creating a render.
- An administrator creates or invites member accounts; there is no public registration form.
- Each member can access only their own reels and associated media. Other members' IDs must not expose reels, render status, streams, downloads, inputs, or mutations.
- Provider keys, voice settings, first-run configuration, and service-wide analytics/configuration are administrator-only.
- Renders remain asynchronous and run through the PHP/Laravel renderer and local FFmpeg on the Hostinger account. The interface reports queued/processing/completed/failed status and lets an owner cancel, retry, view, or download their own render as supported by the current product.
- The Python renderer remains available to the desktop application and is not part of the hosted web render path.
- Gemini and ElevenLabs remain external APIs for content and voice features; rendering itself is not outsourced and runs on Hostinger.

## Implementation direction (subject to approval)

- Use Laravel's existing `User` model and authentication facilities; add explicit administrator/member authorization and a secure invite-only account creation flow. Bootstrap the initial administrator through a protected CLI operation, not an unauthenticated public setup page.
- Associate reels and any user-owned collections with an owner and scope every read/write/media endpoint accordingly. Keep provider configuration and shared background assets global and administrator-controlled.
- Configure production persistence for Hostinger's supported MySQL service and place private uploads, generated media, secrets, and other mutable application data outside the document root where the account permits.
- Keep the Laravel document root at the app's `public` directory. Do not expose `.env`, source, vendor files, or private input media through `public_html`.
- Keep the desktop renderer untouched. Implement a separate PHP/Laravel web renderer using a package compatible with the installed Laravel version or Symfony Process with argument-safe FFmpeg commands. Select the least complex option that supports the current web-render features and Hostinger constraints.
- Configure the shared-host scheduled worker conservatively, with bounded work per invocation, safe timeouts/retries, and no overlapping renders beyond configured resource limits.
- Make service limits configurable and conservative for shared-host CPU, memory, execution time, disk, request frequency, and per-member render volume. Surface failures to the user and logs.

## Acceptance criteria

- An unauthenticated visitor cannot access the studio, generation endpoints, provider setup, or any user's media.
- A member cannot read, stream, download, cancel, retry, or delete another member's reel, including by changing a URL or API identifier.
- Only an administrator can create/invite accounts, change provider credentials, or change global settings.
- A scheduled PHP/Laravel job on the actual Hostinger plan can pick up and complete a web render through local FFmpeg without relying on a web request remaining open or an always-on worker.
- The web render path has no runtime dependency on Python; the existing desktop application still uses its Python renderer successfully.
- Uploaded and generated media remain available after scheduled runs and application deployments, and private source uploads are not directly web-accessible.
- Production configuration uses HTTPS, non-debug mode, strong app/admin credentials, production database credentials, and safe directory permissions.
- Automated tests cover authorization, per-user ownership, invitation/admin flows, web-render output/features, queued rendering behavior, and continued success of existing desktop tests.
- Deployment instructions include the actual web root, environment variables, migrations, FFmpeg setup, cron schedule, storage permissions, backups, and verification steps; any Hostinger-specific limitation is stated plainly.

## Builder decisions

- Use Laravel's established auth, policy, validation, and migration patterns; avoid adding dependencies unless Hostinger compatibility requires one and it is approved.
- Confirm compatibility of any Composer video package with Laravel 13 and the actual FFmpeg build. A package that only wraps a missing or incompatible FFmpeg binary is not a viable renderer.
- Choose a secure invitation implementation compatible with the account's mail availability. If email delivery is unavailable, use a protected administrator-created account flow rather than exposing registration.
- Set safe default quotas and worker cadence based on Hostinger's published/account-specific limits, document how to tune them, and avoid unbounded public workloads.
- Assume a low-volume invite-only launch and serialize FFmpeg work globally at first. Make quotas configurable and increase them only after measuring the actual account's resource limits and render times.
- Preserve existing local SQLite support for development and tests while configuring production for the database supported by the account.

## Open verification

The user confirmed the exact Hostinger plan and its capabilities are unknown. Treat rendering support as unverified and make the compatibility check a hard gate; do not claim the project is Hostinger-ready until a real render succeeds on that account.

Verify FFmpeg/FFprobe installation and execution, SSH/CLI access, cron frequency/runtime limits, memory/CPU limits, disk quota, and PHP version/extensions. If this account cannot meet the renderer requirements, return the incompatibility and stop for the user's direction.
