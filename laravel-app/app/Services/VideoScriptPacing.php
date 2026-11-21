<?php

namespace App\Services;

class VideoScriptPacing
{
    public function sceneCount(float $duration): int
    {
        return min(10, max(3, (int) ceil(max(3.0, $duration) / 7.5)));
    }

    public function targetWordCount(float $duration): int
    {
        return max(8, (int) round(max(3.0, $duration) * 2));
    }
}
