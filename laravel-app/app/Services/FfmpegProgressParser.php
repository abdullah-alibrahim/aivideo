<?php

namespace App\Services;

class FfmpegProgressParser
{
    /** @return array{stage:string,progress:int} */
    public function parse(string $output, float $durationSeconds = 60.0): array
    {
        if (preg_match('/^progress=end$/m', $output) === 1) {
            return ['stage' => 'finalizing', 'progress' => 95];
        }

        preg_match_all('/^out_time_(?:us|ms)=(\d+)$/m', $output, $matches);
        $elapsedSeconds = 0.0;
        if ($matches[1] !== []) {
            $elapsedSeconds = (float) end($matches[1]) / 1_000_000;
        } elseif (preg_match('/^out_time=(\d{2}):(\d{2}):([\d.]+)$/m', $output, $match) === 1) {
            $elapsedSeconds = ((int) $match[1] * 3600) + ((int) $match[2] * 60) + (float) $match[3];
        }

        if ($elapsedSeconds <= 0) {
            return ['stage' => 'encoding', 'progress' => 15];
        }

        $ratio = min(1.0, $elapsedSeconds / max(1.0, $durationSeconds));
        $progress = min(94, 15 + (int) floor($ratio * 79));

        return [
            'stage' => $ratio >= 0.9 ? 'finalizing' : 'encoding',
            'progress' => $progress,
        ];
    }
}
