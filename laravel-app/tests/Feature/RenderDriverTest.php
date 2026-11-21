<?php

namespace Tests\Feature;

use App\Services\FfmpegReelRenderer;
use App\Services\PythonReelRenderer;
use App\Services\RenderEngine;
use Tests\TestCase;

class RenderDriverTest extends TestCase
{
    public function test_desktop_driver_uses_python_renderer(): void
    {
        config()->set('studio.engine.driver', 'python');

        $renderer = app(RenderEngine::class);

        $this->assertInstanceOf(PythonReelRenderer::class, $renderer);
    }

    public function test_ffmpeg_driver_uses_hosted_renderer(): void
    {
        config()->set('studio.engine.driver', 'ffmpeg');

        $this->assertInstanceOf(FfmpegReelRenderer::class, app(RenderEngine::class));
    }

    public function test_python_renderer_writes_preview_using_the_configured_engine(): void
    {
        $scriptPath = tempnam(sys_get_temp_dir(), 'python-renderer-');
        $outputPath = tempnam(sys_get_temp_dir(), 'python-preview-');
        if ($scriptPath === false || $outputPath === false) {
            $this->fail('Unable to allocate preview test files.');
        }
        unlink($outputPath);

        file_put_contents($scriptPath, <<<'PHP'
            <?php
            $index = array_search('--preview-image', $argv, true);
            if ($index === false || ! isset($argv[$index + 1])) {
                exit(2);
            }
            $headline = array_search('--custom-headline', $argv, true);
            $points = array_search('--custom-points', $argv, true);
            if ($headline === false || $points === false
                || ($argv[$headline + 1] ?? null) !== 'A sample headline'
                || ($argv[$points + 1] ?? null) !== 'A sample point') {
                exit(3);
            }
            file_put_contents($argv[$index + 1], 'preview');
            PHP);

        config()->set('studio.engine.python_bin', PHP_BINARY);
        config()->set('studio.engine.script', $scriptPath);

        try {
            app(PythonReelRenderer::class)->renderPreview([
                'language' => 'en',
                'headline' => 'A sample headline',
                'custom_points' => ['A sample point'],
            ], $outputPath);

            $this->assertSame('preview', file_get_contents($outputPath));
        } finally {
            @unlink($scriptPath);
            @unlink($outputPath);
        }
    }
}
