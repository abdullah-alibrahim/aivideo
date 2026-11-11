<?php

return [
    'engine' => [
        'python_bin' => env('AIVIDEO_PYTHON_BIN') ?: base_path(
            PHP_OS_FAMILY === 'Windows' ? '../venv/Scripts/python.exe' : '../venv/bin/python3'
        ),
        'script' => env('AIVIDEO_ENGINE_SCRIPT') ?: base_path('../python_engine/smart_reels.py'),
        'render_timeout_seconds' => max(120, (int) env('STUDIO_RENDER_TIMEOUT_SECONDS', 900)),
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
