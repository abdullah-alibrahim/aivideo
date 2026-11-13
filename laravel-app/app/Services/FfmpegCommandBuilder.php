<?php

namespace App\Services;

use App\Models\Reel;

class FfmpegCommandBuilder
{
    /**
     * @param  array<int, string>  $inputPaths
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function build(
        Reel $reel,
        array $inputPaths,
        ?string $musicPath,
        array $options,
        string $outputPath,
        ?string $voicePath,
        ?string $subtitlePath,
    ): array {
        $size = $this->dimensions((string) ($options['aspect_ratio'] ?? $reel->aspect_ratio ?? '9:16'), (int) ($options['quality'] ?? 1080));
        [$width, $height] = $size;
        $duration = max(3.0, (float) ($reel->duration ?: 10));
        $fps = 30;
        $inputs = ['-y', '-hide_banner', '-loglevel', 'error', '-progress', 'pipe:1', '-nostats'];

        foreach (array_values($inputPaths) as $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                array_push($inputs, '-loop', '1');
            } else {
                array_push($inputs, '-stream_loop', '-1');
            }
            array_push($inputs, '-i', $path);
        }

        $videoCount = count($inputPaths);
        $sourceAudioIndices = array_values(array_filter(
            (array) ($options['_source_audio_indices'] ?? []),
            static fn (mixed $index): bool => is_int($index) && $index >= 0 && $index < count($inputPaths),
        ));
        $musicIndex = null;
        $voiceIndex = null;
        if ($musicPath !== null) {
            $musicIndex = $videoCount;
            array_push($inputs, '-stream_loop', '-1', '-i', $musicPath);
            $videoCount++;
        }
        if ($voicePath !== null) {
            $voiceIndex = $videoCount;
            array_push($inputs, '-i', $voicePath);
            $videoCount++;
        }
        if ($musicIndex === null && (bool) ($options['auto_music'] ?? true)) {
            $musicIndex = $videoCount;
            $ambient = 'aevalsrc=0.055*sin(2*PI*220*t)+0.04*sin(2*PI*261.63*t)+0.03*sin(2*PI*329.63*t):s=44100:d='.$this->number($duration);
            array_push($inputs, '-f', 'lavfi', '-t', (string) $duration, '-i', $ambient);
            $videoCount++;
        } elseif ($musicIndex === null && $voiceIndex === null && $sourceAudioIndices === []) {
            $musicIndex = $videoCount;
            array_push($inputs, '-f', 'lavfi', '-t', (string) $duration, '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100');
        }

        $filterParts = [];
        $transition = $this->transition((string) ($options['scene_transition'] ?? 'template'), (string) $reel->template);
        $transitionSeconds = count($inputPaths) > 1 ? 0.35 : 0.0;
        $clipDuration = ($duration + $transitionSeconds * max(0, count($inputPaths) - 1)) / max(1, count($inputPaths));
        foreach (array_values($inputPaths) as $index => $_path) {
            $label = 'v'.$index;
            $motion = $this->motion((string) ($options['motion_preset'] ?? 'custom'), (string) $reel->camera_motion);
            $motionFilter = match ($motion) {
                'zoom_in' => 'scale='.$width * 1.08.':'.$height * 1.08.':force_original_aspect_ratio=increase,crop='.$width.':'.$height.":x='(in_w-out_w)*t/".$this->number($clipDuration)."':y='(in_h-out_h)/2'",
                'pan' => 'scale='.$width * 1.1.':'.$height * 1.1.':force_original_aspect_ratio=increase,crop='.$width.':'.$height.":x='(in_w-out_w)*t/".$this->number($clipDuration)."':y='(in_h-out_h)/2'",
                'parallax' => 'scale='.$width * 1.1.':'.$height * 1.1.':force_original_aspect_ratio=increase,crop='.$width.':'.$height.":x='(in_w-out_w)*(0.5+0.08*sin(2*PI*t/".$this->number($clipDuration)."))':y='(in_h-out_h)*(0.5+0.04*sin(PI*t/".$this->number($clipDuration)."))'",
                default => 'scale='.$width.':'.$height.':force_original_aspect_ratio=increase,crop='.$width.':'.$height,
            };
            $filterParts[] = '['.$index.':v]'.$motionFilter.',setsar=1,fps='.$fps.',trim=duration='.$this->number($clipDuration).',settb=AVTB,setpts=PTS-STARTPTS['.$label.']';
        }

        if ($inputPaths === []) {
            $filterParts[] = 'color=c=black:s='.$width.'x'.$height.':r='.$fps.':d='.$duration.'[joined]';
        } elseif (count($inputPaths) === 1) {
            $filterParts[] = '[v0]null[joined]';
        } else {
            $previous = 'v0';
            $elapsed = $clipDuration - $transitionSeconds;
            for ($index = 1; $index < count($inputPaths); $index++) {
                $next = 'x'.$index;
                $filterParts[] = '['.$previous.'][v'.$index.']xfade=transition='.$transition.':duration='.$this->number($transitionSeconds).':offset='.$this->number($elapsed).'['.$next.']';
                $previous = $next;
                $elapsed += $clipDuration - $transitionSeconds;
            }
            $filterParts[] = '['.$previous.']null[joined]';
        }

        $base = 'joined';
        $dimming = max(0, min(80, (int) ($options['background_dimming'] ?? 35)));
        if ($dimming > 0) {
            $filterParts[] = '['.$base.']drawbox=x=0:y=0:w=iw:h=ih:color=black@'.$this->number($dimming / 100).':t=fill[dimmed]';
            $base = 'dimmed';
        }

        $accent = $this->accentColor((string) ($options['accent_color'] ?? '#fb7185'));
        $template = $this->template((string) $reel->template, $accent);
        if ($template !== '') {
            $filterParts[] = '['.$base.']'.$template.'[styled]';
            $base = 'styled';
        }

        $textFiles = is_array($options['_text_files'] ?? null) ? $options['_text_files'] : [];
        $font = trim((string) config('studio.engine.arabic_font', ''));
        if ($font === '') {
            $font = base_path('../python_engine/fonts/NotoKufiArabic.ttf');
        }
        $fontScale = max(80, min(130, (int) ($options['font_scale'] ?? 100)));
        $handlePath = $textFiles['handle'] ?? null;
        if (is_string($handlePath) && $handlePath !== '') {
            $handleFilter = 'drawtext=fontfile='.$this->filterValue($font)
                .':textfile='.$this->filterValue($handlePath)
                .':expansion=none:text_shaping=1:fontcolor=white:fontsize='.$this->fontSize($height, $fontScale, 0.024)
                .':box=1:boxcolor=black@0.35:boxborderw=12:x=(w-text_w)/2:y=h*0.09';
            $filterParts[] = '['.$base.']'.$handleFilter.'[branded]';
            $base = 'branded';
        }

        if ($subtitlePath !== null && (bool) ($options['captions'] ?? true)) {
            $filterParts[] = '['.$base.']subtitles=filename='.$this->filterValue($subtitlePath).':force_style='.$this->filterValue('FontName=Noto Kufi Arabic,FontSize=26,Outline=2,Shadow=0,Alignment=2,MarginV=100').'[subtitled]';
            $base = 'subtitled';
        }

        $headlinePath = $textFiles['headline'] ?? null;
        if (is_string($headlinePath) && $headlinePath !== '') {
            $headline = 'drawtext=fontfile='.$this->filterValue($font)
                .':textfile='.$this->filterValue($headlinePath)
                .':expansion=none:text_shaping=1:fontcolor=white:fontsize='.$this->fontSize($height, $fontScale, 0.052)
                .':line_spacing=12:box=1:boxcolor=black@0.48:boxborderw=28:x=(w-text_w)/2:y=h*0.38';
            $filterParts[] = '['.$base.']'.$headline.'[headline]';
            $base = 'headline';
        }

        foreach (array_values((array) ($textFiles['points'] ?? [])) as $index => $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            $start = $duration * $index / max(1, count((array) ($textFiles['points'] ?? [])));
            $end = $duration * ($index + 1) / max(1, count((array) ($textFiles['points'] ?? [])));
            $motion = $this->textMotion((string) ($options['text_motion'] ?? 'vertical'));
            $x = match ($motion) {
                'slide_left' => 'w*0.14+(w-text_w)*min(1\\,t/'.$this->number(max(0.1, $end - $start)).')',
                'slide_right' => 'w*0.86-(w-text_w)*min(1\\,t/'.$this->number(max(0.1, $end - $start)).')',
                default => '(w-text_w)/2',
            };
            $point = 'drawtext=fontfile='.$this->filterValue($font)
                .':textfile='.$this->filterValue($path)
                .':expansion=none:text_shaping=1:fontcolor=white:fontsize='.$this->fontSize($height, $fontScale, 0.037)
                .':line_spacing=10:box=1:boxcolor='.$accent.'@0.88:boxborderw=24:x='.$x.':y=h*0.52'
                .":enable='between(t,".$this->number($start).','.$this->number($end).")'";
            $filterParts[] = '['.$base.']'.$point.'[point'.$index.']';
            $base = 'point'.$index;
        }
        $ctaPath = $textFiles['cta'] ?? null;
        if (is_string($ctaPath) && $ctaPath !== '') {
            $ctaFilter = 'drawtext=fontfile='.$this->filterValue($font)
                .':textfile='.$this->filterValue($ctaPath)
                .':expansion=none:text_shaping=1:fontcolor=white:fontsize='.$this->fontSize($height, $fontScale, 0.034)
                .':box=1:boxcolor='.$accent.'@0.88:boxborderw=22:x=(w-text_w)/2:y=h*0.78'
                .":enable='between(t,".$this->number(max(0, $duration - 1.8)).','.$this->number($duration).")'";
            $filterParts[] = '['.$base.']'.$ctaFilter.'[cta]';
            $base = 'cta';
        }
        $filterParts[] = '['.$base.']format=yuv420p[vout]';

        $audioLabels = [];
        foreach ($sourceAudioIndices as $inputIndex) {
            $delay = (int) round($clipDuration * $inputIndex * 1000);
            $label = 'sourceaudio'.$inputIndex;
            $filterParts[] = '['.$inputIndex.':a:0]atrim=duration='.$this->number($clipDuration)
                .',asetpts=PTS-STARTPTS,adelay='.$delay.'|'.$delay.',volume=0.16['.$label.']';
            $audioLabels[] = $label;
        }
        if ($musicIndex !== null) {
            $volume = $voiceIndex !== null ? ($musicPath === null ? '0.08' : '0.12') : ($sourceAudioIndices !== [] ? '0.16' : '0.24');
            $label = 'bgm';
            $fadeOutStart = max(0, $duration - 1);
            $filterParts[] = '['.$musicIndex.':a]volume='.$volume.',afade=t=in:st=0:d=0.6,afade=t=out:st='.$this->number($fadeOutStart).':d=1['.$label.']';
            $audioLabels[] = $label;
        }
        if ($voiceIndex !== null) {
            $filterParts[] = '['.$voiceIndex.':a]volume=1.0[voice]';
            $audioLabels[] = 'voice';
        }
        if (count($audioLabels) === 1) {
            $filterParts[] = '['.$audioLabels[0].']alimiter=limit=0.95[aout]';
        } else {
            $inputsGraph = implode('', array_map(static fn (string $label): string => '['.$label.']', $audioLabels));
            $filterParts[] = $inputsGraph.'amix=inputs='.count($audioLabels).':duration=longest:dropout_transition=2,alimiter=limit=0.95[aout]';
        }

        return [
            (string) config('studio.engine.ffmpeg_bin', 'ffmpeg'),
            ...$inputs,
            '-filter_complex', implode(';', $filterParts),
            '-map', '[vout]',
            '-map', '[aout]',
            '-t', $this->number($duration),
            '-r', (string) $fps,
            '-threads', '2',
            '-c:v', 'libx264',
            '-preset', (int) ($options['quality'] ?? 1080) === 720 ? 'veryfast' : 'medium',
            '-crf', (int) ($options['quality'] ?? 1080) === 720 ? '26' : '21',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            $outputPath,
        ];
    }

    /** @param array<string, mixed> $options @return list<string> */
    public function buildPreview(array $options, string $outputPath): array
    {
        [$width, $height] = $this->dimensions((string) ($options['aspect_ratio'] ?? '9:16'), (int) ($options['quality'] ?? 720));
        $textFiles = is_array($options['_text_files'] ?? null) ? $options['_text_files'] : [];
        $font = trim((string) config('studio.engine.arabic_font', ''));
        if ($font === '') {
            $font = base_path('../python_engine/fonts/NotoKufiArabic.ttf');
        }
        $filters = [];
        if (is_string($textFiles['headline'] ?? null)) {
            $filters[] = 'drawtext=fontfile='.$this->filterValue($font).':textfile='.$this->filterValue($textFiles['headline'])
                .':expansion=none:text_shaping=1:fontcolor=white:fontsize='.$this->fontSize($height, (int) ($options['font_scale'] ?? 100), 0.06)
                .':box=1:boxcolor=black@0.5:boxborderw=30:x=(w-text_w)/2:y=h*0.36';
        }
        if (is_string($textFiles['handle'] ?? null) && $textFiles['handle'] !== '') {
            $filters[] = 'drawtext=fontfile='.$this->filterValue($font).':textfile='.$this->filterValue($textFiles['handle'])
                .':expansion=none:text_shaping=1:fontcolor=white:fontsize='.$this->fontSize($height, (int) ($options['font_scale'] ?? 100), 0.025)
                .':box=1:boxcolor=black@0.35:boxborderw=12:x=(w-text_w)/2:y=h*0.08';
        }
        foreach (array_values((array) ($textFiles['points'] ?? [])) as $index => $path) {
            if (is_string($path)) {
                $filters[] = 'drawtext=fontfile='.$this->filterValue($font).':textfile='.$this->filterValue($path)
                    .':expansion=none:text_shaping=1:fontcolor=white:fontsize='.$this->fontSize($height, (int) ($options['font_scale'] ?? 100), 0.04)
                    .':box=1:boxcolor='.$this->accentColor((string) ($options['accent_color'] ?? '#fb7185')).'@0.85:boxborderw=20:x=(w-text_w)/2:y=h*'.(0.54 + $index * 0.09);
            }
        }

        return [
            (string) config('studio.engine.ffmpeg_bin', 'ffmpeg'),
            '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'color=c=0x111827:s='.$width.'x'.$height.':d=1',
            '-vf', implode(',', $filters),
            '-frames:v', '1',
            '-threads', '1',
            $outputPath,
        ];
    }

    /** @return list<string> */
    public function buildThumbnail(string $inputPath, string $outputPath): array
    {
        return [
            (string) config('studio.engine.ffmpeg_bin', 'ffmpeg'),
            '-y', '-hide_banner', '-loglevel', 'error', '-ss', '0',
            '-i', $inputPath, '-frames:v', '1', '-threads', '1', '-update', '1', '-q:v', '2', $outputPath,
        ];
    }

    /** @return array{int, int} */
    private function dimensions(string $aspectRatio, int $quality): array
    {
        $height = $quality === 720 ? 720 : 1080;

        return match ($aspectRatio) {
            '1:1' => [$height, $height],
            '16:9' => [intdiv($height * 16, 9), $height],
            default => [(int) (ceil(($height * 9 / 16) / 2) * 2), $height],
        };
    }

    private function motion(string $preset, string $cameraMotion): string
    {
        if ($preset === 'minimal') {
            return 'static';
        }
        if ($preset === 'subtle_story' || $preset === 'cinematic_depth') {
            return 'parallax';
        }
        if ($preset === 'hook_focus') {
            return 'zoom_in';
        }
        if ($preset === 'news_wipe') {
            return 'pan';
        }

        return in_array($cameraMotion, ['static', 'zoom_in', 'pan', 'parallax'], true) ? $cameraMotion : 'static';
    }

    private function textMotion(string $motion): string
    {
        return in_array($motion, ['slide_right', 'slide_left'], true) ? $motion : 'vertical';
    }

    private function transition(string $transition, string $template): string
    {
        if ($transition === 'template') {
            return match ($template) {
                'clean_news', 'bold_stripe' => 'wipeleft',
                'quote_card' => 'slideright',
                default => 'fade',
            };
        }

        return match ($transition) {
            'wipe' => 'wipeleft',
            'slide' => 'slideleft',
            'varied' => 'smoothleft',
            default => 'fade',
        };
    }

    private function template(string $template, string $accent): string
    {
        return match ($template) {
            'clean_news' => 'drawbox=x=0:y=ih*0.68:w=iw:h=ih*0.32:color=black@0.68:t=fill',
            'cinematic' => 'drawbox=x=0:y=0:w=iw:h=ih*0.1:color=black@0.42:t=fill,drawbox=x=0:y=ih*0.9:w=iw:h=ih*0.1:color=black@0.42:t=fill',
            'quote_card' => 'drawbox=x=iw*0.08:y=ih*0.28:w=iw*0.84:h=ih*0.46:color=black@0.54:t=fill',
            'bold_stripe' => 'drawbox=x=0:y=ih*0.36:w=iw:h=ih*0.32:color='.$accent.'@0.8:t=fill',
            default => 'drawbox=x=iw*0.06:y=ih*0.3:w=iw*0.88:h=ih*0.43:color=black@0.3:t=fill',
        };
    }

    private function accentColor(string $color): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#fb7185';
    }

    private function filterValue(string $value): string
    {
        $escaped = str_replace(
            ['\\', ':', "'", ',', '[', ']', '%'],
            ['\\\\', '\\:', "\\'", '\\,', '\\[', '\\]', '%%'],
            $value,
        );

        return "'".$escaped."'";
    }

    private function fontSize(int $height, int $scale, float $ratio): int
    {
        return max(24, (int) round($height * $ratio * max(80, min(130, $scale)) / 100));
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
