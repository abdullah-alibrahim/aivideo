<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class FfmpegCapabilities
{
    /**
     * @return array{
     *     ffmpeg:string|null,
     *     ffprobe:string|null,
     *     version:string|null,
     *     proc_open:bool,
     *     font_readable:bool,
     *     h264:bool,
     *     aac:bool,
     *     drawtext:bool,
     *     subtitles:bool,
     *     text_shaping:bool
     * }
     */
    public function inspect(): array
    {
        $procOpen = function_exists('proc_open');
        $ffmpeg = $this->resolveExecutable((string) config('studio.engine.ffmpeg_bin', 'ffmpeg'));
        $ffprobe = $this->resolveExecutable((string) config('studio.engine.ffprobe_bin', 'ffprobe'));
        $versionOutput = $procOpen && $ffmpeg !== null ? $this->run([$ffmpeg, '-version']) : null;
        $ffprobeOutput = $procOpen && $ffprobe !== null ? $this->run([$ffprobe, '-version']) : null;
        $encoderOutput = $procOpen && $ffmpeg !== null ? $this->run([$ffmpeg, '-hide_banner', '-encoders']) : null;
        $filterOutput = $procOpen && $ffmpeg !== null ? $this->run([$ffmpeg, '-hide_banner', '-filters']) : null;
        $drawtextOutput = $procOpen && $ffmpeg !== null
            ? $this->run([$ffmpeg, '-hide_banner', '-h', 'filter=drawtext'])
            : null;
        $font = trim((string) config('studio.engine.arabic_font', ''));
        if ($font === '') {
            $font = base_path('../python_engine/fonts/NotoKufiArabic.ttf');
        }

        return [
            'ffmpeg' => $versionOutput !== null ? $ffmpeg : null,
            'ffprobe' => $ffprobeOutput !== null ? $ffprobe : null,
            'version' => $versionOutput === null ? null : (strtok($versionOutput, "\n") ?: null),
            'proc_open' => $procOpen,
            'font_readable' => is_file($font) && is_readable($font),
            'h264' => $encoderOutput !== null && preg_match('/\blibx264\b/i', $encoderOutput) === 1,
            'aac' => $encoderOutput !== null && preg_match('/\baac\b/i', $encoderOutput) === 1,
            'drawtext' => $filterOutput !== null && preg_match('/\bdrawtext\b/', $filterOutput) === 1,
            'subtitles' => $filterOutput !== null && preg_match('/\bsubtitles\b/', $filterOutput) === 1,
            'text_shaping' => $drawtextOutput !== null && preg_match('/\btext_shaping\b/', $drawtextOutput) === 1,
        ];
    }

    private function resolveExecutable(string $binary): ?string
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        return (new ExecutableFinder)->find($binary);
    }

    /** @param array<int, string> $command */
    private function run(array $command): ?string
    {
        $process = new Process($command);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput()."\n".$process->getErrorOutput()) ?: null;
    }
}
