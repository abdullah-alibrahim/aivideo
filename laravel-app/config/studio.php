<?php

return [
    // User-data root override; empty value uses the per-OS default (StudioPaths).
    'data_dir' => env('AIVIDEO_DATA_DIR'),
    'version' => env('STUDIO_VERSION', '1.0.0'),
    'keys' => [
        // Commercial builds set STUDIO_ALLOW_ENV_KEYS=0 so keys never come from .env.
        'allow_env' => (bool) env('STUDIO_ALLOW_ENV_KEYS', true),
    ],
    'secrets' => [
        // "auto" picks Keychain on macOS, Credential Manager on Windows, and the
        // encrypted-file fallback elsewhere. Override for tests or custom setups.
        'store' => env('STUDIO_SECRETS_STORE', 'auto'),
    ],
    'updates' => [
        'manifest_url' => (string) env('STUDIO_UPDATE_MANIFEST_URL', ''),
    ],
    'engine' => [
        'driver' => env('AIVIDEO_RENDER_DRIVER', 'ffmpeg'),
        'ffmpeg_bin' => env('AIVIDEO_FFMPEG_BIN') ?: '/home/u817201413/bin/ffmpeg',
        'ffprobe_bin' => env('AIVIDEO_FFPROBE_BIN') ?: '/home/u817201413/bin/ffprobe',
        'render_timeout_seconds' => max(120, (int) env('STUDIO_RENDER_TIMEOUT_SECONDS', 900)),
        'queue_worker_timeout_seconds' => max(60, (int) env('STUDIO_QUEUE_WORKER_TIMEOUT_SECONDS', 1200)),
        'arabic_font' => env('AIVIDEO_ARABIC_FONT', ''),
    ],
    'security' => [
        'enabled' => (bool) env('STUDIO_AUTH_ENABLED', false),
        'username' => env('STUDIO_AUTH_USERNAME', ''),
        'password' => env('STUDIO_AUTH_PASSWORD', ''),
    ],
    'limits' => [
        'max_video_mb' => (int) env('STUDIO_MAX_VIDEO_MB', 100),
        'max_music_mb' => (int) env('STUDIO_MAX_MUSIC_MB', 20),
        'max_concurrent_renders' => max(1, (int) env('STUDIO_MAX_CONCURRENT_RENDERS', 1)),
        'render_lock_seconds' => max(60, (int) env('STUDIO_RENDER_LOCK_SECONDS', 900)),
        'stale_after_minutes' => max(5, (int) env('STUDIO_STALE_AFTER_MINUTES', 30)),
        'min_free_space_mb' => max(128, (int) env('STUDIO_MIN_FREE_SPACE_MB', 1024)),
    ],
];
