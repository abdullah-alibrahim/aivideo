<?php

namespace App\Services;

use App\Models\Reel;

interface RenderEngine
{
    /**
     * @param  array<int, string>  $inputPaths
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function render(Reel $reel, array $inputPaths, ?string $musicPath, array $options, string $outputRelativePath): array;

    /** @param array<string, mixed> $options */
    public function renderPreview(array $options, string $outputPath): void;
}
