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
        $duration = max(3.0, (float) ($options['_render_duration'] ?? $reel->duration ?: 10));
        $fps = 30;
        $quality = (int) ($options['quality'] ?? 1080);
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
            $mood = $this->musicMood($options['music_mood'] ?? null, $reel->niche);
            $ambient = $this->ambientSource($mood, $duration);
            array_push($inputs, '-f', 'lavfi', '-t', (string) $duration, '-i', $ambient);
            $videoCount++;
        } elseif ($musicIndex === null && $voiceIndex === null && $sourceAudioIndices === []) {
            $musicIndex = $videoCount;
            array_push($inputs, '-f', 'lavfi', '-t', (string) $duration, '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100');
        }

        $logoIndex = null;
        $logoPath = (string) ($options['_logo_path'] ?? '');
        if ($logoPath !== '' && is_file($logoPath)) {
            $logoIndex = $videoCount;
            array_push($inputs, '-loop', '1', '-i', $logoPath);
            $videoCount++;
        }

        $filterParts = [];
        $transitionMode = (string) ($options['scene_transition'] ?? 'template');
        $templateName = (string) $reel->template;
        $transitionSeconds = count($inputPaths) > 1 ? $this->transitionDuration($transitionMode) : 0.0;
        $clipDuration = ($duration + $transitionSeconds * max(0, count($inputPaths) - 1)) / max(1, count($inputPaths));
        $clipFrames = max(1, (int) round($clipDuration * $fps));
        foreach (array_values($inputPaths) as $index => $_path) {
            $label = 'v'.$index;
            $motion = $this->motionForScene((string) ($options['motion_preset'] ?? 'custom'), (string) $reel->camera_motion, $index);
            $motionFilter = match ($motion) {
                'zoom_in' => 'scale='.$width * 1.08.':'.$height * 1.08.':force_original_aspect_ratio=increase,crop='.$width.':'.$height.":x='(in_w-out_w)*t/".$this->number($clipDuration)."':y='(in_h-out_h)/2'",
                'pan' => 'scale='.$width * 1.1.':'.$height * 1.1.':force_original_aspect_ratio=increase,crop='.$width.':'.$height.":x='(in_w-out_w)*t/".$this->number($clipDuration)."':y='(in_h-out_h)/2'",
                'parallax' => 'scale='.$width * 1.1.':'.$height * 1.1.':force_original_aspect_ratio=increase,crop='.$width.':'.$height.":x='(in_w-out_w)*(0.5+0.08*sin(2*PI*t/".$this->number($clipDuration)."))':y='(in_h-out_h)*(0.5+0.04*sin(PI*t/".$this->number($clipDuration)."))'",
                'drift' => 'scale='.$width * 1.12.':'.$height * 1.12.':force_original_aspect_ratio=increase,crop='.$width.':'.$height.":x='(in_w-out_w)*t/".$this->number($clipDuration)."':y='(in_h-out_h)*(1-t/".$this->number($clipDuration).")'",
                'tilt_up' => 'scale='.$width * 1.12.':'.$height * 1.12.':force_original_aspect_ratio=increase,crop='.$width.':'.$height.":x='(in_w-out_w)/2':y='(in_h-out_h)*(1-t/".$this->number($clipDuration).")'",
                'handheld' => 'scale='.$width * 1.1.':'.$height * 1.1.':force_original_aspect_ratio=increase,crop='.$width.':'.$height.":x='(in_w-out_w)/2+(in_w-out_w)*0.03*sin(2*PI*t*1.7)':y='(in_h-out_h)/2+(in_h-out_h)*0.03*sin(2*PI*t*1.3)'",
                'zoom_out' => 'scale='.$width.':'.$height.':force_original_aspect_ratio=increase,crop='.$width.':'.$height.",zoompan=z='max(1,1.12-0.12*on/".$clipFrames.")':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:s=".$width.'x'.$height.':fps='.$fps,
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
                $sceneTransition = $this->transitionFor($transitionMode, $templateName, $index);
                $filterParts[] = '['.$previous.'][v'.$index.']xfade=transition='.$sceneTransition.':duration='.$this->number($transitionSeconds).':offset='.$this->number($elapsed).'['.$next.']';
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

        $grade = $this->colorGradeFilters($options['color_grade'] ?? 'none');
        if ($grade !== []) {
            $filterParts[] = '['.$base.']'.implode(',', $grade).'[graded]';
            $base = 'graded';
        }

        $overlays = $this->overlayFilters($options);
        if ($overlays !== []) {
            $filterParts[] = '['.$base.']'.implode(',', $overlays).'[overlaid]';
            $base = 'overlaid';
        }

        $textFiles = is_array($options['_text_files'] ?? null) ? $options['_text_files'] : [];
        $font = $this->resolveFontPath();

        // Generate ASS subtitle file using AssOverlayBuilder for proper Arabic text rendering
        // Derive ASS path from the subtitlePath parameter (which is an SRT file in the work directory)
        $assPath = $subtitlePath !== null ? str_replace('.srt', '.ass', $subtitlePath) : null;
        if ($assPath !== null) {
            $assDir = dirname($assPath);
            if (! is_dir($assDir)) {
                @mkdir($assDir, 0755, true);
            }
            $assBuilder = new AssOverlayBuilder;
            $assContent = $assBuilder->build([
                'headline' => $this->textSource($options['headline'] ?? $textFiles['headline'] ?? ''),
                'points' => $this->textSources($options['custom_points'] ?? $textFiles['points'] ?? []),
                'cta' => $this->textSource($options['cta'] ?? $textFiles['cta'] ?? ''),
                'handle' => $this->textSource($options['handle'] ?? $textFiles['handle'] ?? ''),
            ], $options, $width, $height, $duration);
            file_put_contents($assPath, $assContent);
        }

        // The ASS overlay owns every on-screen text block. Do not force style
        // values here: they would override the positions computed above and
        // stack the headline, points and CTA on top of each other.
        if ($assPath !== null && is_file($assPath)) {
            $filterParts[] = '['.$base.']subtitles=filename='.$this->filterValue($assPath)
                .$this->fontsDirectoryOption($font).'[headlined]';
            $base = 'headlined';
        }

        if ($logoIndex !== null) {
            $logoWidth = max(64, (int) round($width * 0.14 / 2) * 2);
            $filterParts[] = '['.$logoIndex.':v]scale='.$logoWidth.':-2,format=rgba,colorchannelmixer=aa=0.9[brand]';
            $filterParts[] = '['.$base.'][brand]overlay=W-w-24:24[branded]';
            $base = 'branded';
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
            $volume = $voiceIndex !== null ? '0.12' : ($sourceAudioIndices !== [] ? '0.16' : '0.24');
            $label = 'bgm';
            $fadeOutStart = max(0, $duration - 1);
            $filterParts[] = '['.$musicIndex.':a]volume='.$volume.',afade=t=in:st=0:d=0.6,afade=t=out:st='.$this->number($fadeOutStart).':d=1['.$label.']';
            $audioLabels[] = $label;
        }
        if ($voiceIndex !== null) {
            $filterParts[] = '['.$voiceIndex.':a]volume=1.0[voice]';
            $audioLabels[] = 'voice';
        }
        if ($musicIndex !== null && $voiceIndex !== null) {
            $filterParts[] = '[bgm][voice]sidechaincompress=threshold=0.12:ratio=4:attack=5:release=200[bgm_ducked]';
            // replace bgm in audioLabels with bgm_ducked
            $audioLabels = array_map(static fn (string $lbl): string => $lbl === 'bgm' ? 'bgm_ducked' : $lbl, $audioLabels);
        }
        if (! empty($options['sound_effects'])) {
            $timelineStarts = $this->timelineStarts($options['timeline'] ?? null);
            if ($timelineStarts !== []) {
                // An edited timeline is authoritative: hit every scene entry
                // exactly when the caption appears instead of guessing.
                $effectTimes = $timelineStarts;
            } else {
                $textFiles = is_array($options['_text_files'] ?? null) ? $options['_text_files'] : [];
                $pointCount = count($this->textSources($options['custom_points'] ?? $textFiles['points'] ?? []));
                $headlineEnd = $pointCount > 0
                    ? min($duration, max(2.5, $duration * 0.34))
                    : max(2.5, $duration - 2.0);
                $pointSpan = max(0.1, max($headlineEnd, $duration - 2.0) - $headlineEnd);
                $effectTimes = [0.12];
                for ($index = 0; $index < $pointCount; $index++) {
                    $effectTimes[] = $headlineEnd + ($pointSpan / max(1, $pointCount) * $index);
                }
            }
            $sfxStyle = (string) ($options['sfx_style'] ?? 'auto');
            $sfxVolume = max(0.02, min(0.5, (float) ($options['sfx_volume'] ?? 0.16)));

            foreach ($effectTimes as $index => $start) {
                $label = 'sfx'.$index;
                $delay = (int) round($start * 1000);
                $filterParts[] = $this->sfxChain($sfxStyle, $index, $sfxVolume)
                    .',adelay='.$delay.'|'.$delay.'['.$label.']';
                $audioLabels[] = $label;
            }
        }
        if ($audioLabels === []) {
            // No music, voiceover, source audio or effects: keep a silent
            // stereo track so the MP4 still carries an audio stream.
            $filterParts[] = 'anullsrc=r=44100:cl=stereo,atrim=duration='.$this->number($duration)
                .',aformat=sample_rates=44100:channel_layouts=stereo[aout]';
        } elseif (count($audioLabels) === 1) {
            $filterParts[] = '['.$audioLabels[0].']alimiter=limit=0.95[aout]';
        } else {
            $inputsGraph = implode('', array_map(static fn (string $label): string => '['.$label.']', $audioLabels));
            $filterParts[] = $inputsGraph.'amix=inputs='.count($audioLabels).':duration=longest:dropout_transition=2,alimiter=limit=0.95[aout]';
        }

        return [
            (string) config('studio.engine.ffmpeg_bin', 'ffmpeg'),
            ...$inputs,
            '-filter_complex_threads', '1',
            '-filter_complex', implode(';', $filterParts),
            '-map', '[vout]',
            '-map', '[aout]',
            '-t', $this->number($duration),
            '-r', (string) $fps,
            '-threads', '1',
            '-c:v', 'libx264',
            '-preset', $quality <= 480 ? 'ultrafast' : ($quality === 720 ? 'veryfast' : 'medium'),
            '-crf', $quality <= 480 ? '28' : ($quality === 720 ? '25' : '23'),
            // Cap the peak bitrate so fast motion and transitions cannot
            // balloon the download size; social platforms re-encode anyway.
            '-maxrate', $quality <= 480 ? '1500k' : ($quality === 720 ? '3M' : '6M'),
            '-bufsize', $quality <= 480 ? '3M' : ($quality === 720 ? '6M' : '12M'),
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
        $font = $this->resolveFontPath();

        // Generate ASS subtitle file for preview using AssOverlayBuilder
        $assBuilder = new AssOverlayBuilder;
        $assContent = $assBuilder->build([
            'headline' => $this->textSource($options['headline'] ?? $textFiles['headline'] ?? ''),
            'points' => $this->textSources($options['custom_points'] ?? $textFiles['points'] ?? []),
            'cta' => $this->textSource($options['cta'] ?? $textFiles['cta'] ?? ''),
            'handle' => $this->textSource($options['handle'] ?? $textFiles['handle'] ?? ''),
        ], $options, $width, $height, 10.0);

        $assPath = tempnam(sys_get_temp_dir(), 'ass_').'.ass';
        file_put_contents($assPath, $assContent);

        // A single subtitles filter chain: the ASS file already contains every
        // text block, so captions must not be burned in a second time.
        $filters = [];
        if (is_file($assPath)) {
            $filters[] = 'subtitles=filename='.$this->filterValue($assPath).$this->fontsDirectoryOption($font);
        }
        $filters[] = 'format=yuv420p';

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
        $height = $quality <= 480 ? 480 : ($quality === 720 ? 720 : 1080);

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
        if ($preset === 'dynamic') {
            return 'dynamic';
        }

        $allowed = ['static', 'zoom_in', 'pan', 'parallax', 'zoom_out', 'drift', 'tilt_up', 'handheld'];

        return in_array($cameraMotion, $allowed, true) ? $cameraMotion : 'static';
    }

    /**
     * Resolve one clip's camera move. The dynamic preset cycles through
     * distinct moves per scene so a multi-clip montage never feels static.
     */
    private function motionForScene(string $preset, string $cameraMotion, int $index): string
    {
        $motion = $this->motion($preset, $cameraMotion);
        if ($motion !== 'dynamic') {
            return $motion;
        }

        return ['zoom_in', 'pan', 'parallax', 'drift', 'tilt_up'][$index % 5];
    }

    /**
     * Faster, punchier transitions for glitch/whip cuts; longer, softer
     * blends for cinematic zoom-blur moves.
     */
    private function transitionDuration(string $mode): float
    {
        return match ($mode) {
            'glitch' => 0.2,
            'whip_pan' => 0.26,
            'zoom_blur' => 0.45,
            'auto' => 0.3,
            default => 0.35,
        };
    }

    /**
     * Optional colour grade. Applied before overlays and text so captions stay
     * crisp and correctly coloured.
     *
     * @return list<string>
     */
    private function colorGradeFilters(mixed $grade): array
    {
        return match ((string) $grade) {
            'cinematic' => [
                'eq=contrast=1.06:saturation=1.08:gamma=1.02',
                'colorbalance=rs=0.04:gs=0.01:bs=-0.04',
                'vignette=PI/7',
            ],
            'warm' => ['colorbalance=rs=0.08:gs=0.03:bs=-0.08', 'eq=saturation=1.05'],
            'cool' => ['colorbalance=rs=-0.05:gs=0.01:bs=0.08', 'eq=saturation=1.02'],
            'vivid' => ['eq=contrast=1.1:saturation=1.22'],
            'mono' => ['hue=s=0', 'eq=contrast=1.08'],
            default => [],
        };
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
            'zoom_blur' => 'zoomin',
            'glitch' => 'pixelize',
            'whip_pan' => 'smoothleft',
            default => 'fade',
        };
    }

    /**
     * Resolve the ffmpeg xfade transition for one scene boundary.
     *
     * Cinematic modes alternate between two compatible xfade transitions so a
     * long reel never repeats the exact same motion at every cut.
     */
    private function transitionFor(string $mode, string $template, int $index): string
    {
        $even = $index % 2 === 0;

        return match ($mode) {
            'auto' => ['zoomin', 'smoothleft', 'pixelize', 'smoothright', 'fade'][$index % 5],
            'varied' => ['fade', 'smoothleft', 'slideright', 'wipeleft'][$index % 4],
            'zoom_blur' => $even ? 'zoomin' : 'zoomout',
            'glitch' => $even ? 'pixelize' : 'hblur',
            'whip_pan' => $even ? 'smoothleft' : 'smoothright',
            default => $this->transition($mode, $template),
        };
    }

    /**
     * Scene entry times from an edited timeline, or an empty list when the
     * wizard did not pin a timeline.
     *
     * @return list<float>
     */
    private function timelineStarts(mixed $timeline): array
    {
        if (! is_array($timeline)) {
            return [];
        }

        $starts = [];
        foreach ($timeline as $index => $segment) {
            if (! is_array($segment)) {
                continue;
            }
            $start = max(0.0, (float) ($segment['start'] ?? 0));
            $starts[] = $index === 0 && $start <= 0.0 ? 0.12 : $start;
        }
        sort($starts);

        return array_slice(array_values(array_unique(array_map(
            static fn (float $start): float => round($start, 2),
            $starts,
        ))), 0, 12);
    }

    /**
     * Optional cinematic overlays. Every filter here ships with stock FFmpeg so
     * the graph keeps working on minimal server builds.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function overlayFilters(array $options): array
    {
        $filters = [];

        if (! empty($options['overlay_light_leak'])) {
            // Warm highlight roll-off plus a soft vignette reads as a light leak.
            $filters[] = 'colorbalance=rs=0.10:gs=0.035:bs=-0.07';
            $filters[] = 'vignette=PI/6';
        }
        if (! empty($options['overlay_dust'])) {
            // Full-strength noise is invisible on a phone but can double the
            // bitrate, so both overlay grains are kept subtle.
            $filters[] = 'noise=alls=10:allf=t+u';
        }
        if (! empty($options['overlay_grain'])) {
            $filters[] = 'noise=alls=4:allf=t+u';
        }

        return $filters;
    }

    private function musicMood(mixed $requested, ?string $niche): string
    {
        $requested = trim((string) $requested);
        if (in_array($requested, ['epic', 'reflective', 'energetic', 'calm'], true)) {
            return $requested;
        }

        return match ($niche) {
            'tech', 'business' => 'energetic',
            'money' => 'epic',
            'habits', 'psychology', 'productivity' => 'reflective',
            default => 'calm',
        };
    }

    /**
     * Procedural, royalty-free background bed shaped by the reel mood.
     */
    private function ambientSource(string $mood, float $duration): string
    {
        $d = $this->number($duration);

        return match ($mood) {
            'epic' => 'aevalsrc=(0.055*sin(2*PI*98*t)+0.04*sin(2*PI*146.83*t)+0.03*sin(2*PI*196*t))*(0.7+0.3*sin(2*PI*0.08*t)):s=44100:d='.$d,
            'energetic' => 'aevalsrc=(0.05*sin(2*PI*130.81*t)+0.04*sin(2*PI*164.81*t)+0.03*sin(2*PI*196*t))*(0.7+0.3*sin(2*PI*3.2*t)):s=44100:d='.$d,
            'reflective' => 'aevalsrc=(0.05*sin(2*PI*110*t)+0.04*sin(2*PI*164.81*t)+0.03*sin(2*PI*220*t))*(0.75+0.25*sin(2*PI*0.12*t)):s=44100:d='.$d,
            default => 'aevalsrc=0.055*sin(2*PI*220*t)+0.04*sin(2*PI*261.63*t)+0.03*sin(2*PI*329.63*t):s=44100:d='.$d,
        };
    }

    /**
     * Build a short, distinct sound effect chain for a scene entry.
     *
     * The result is mixed into the export so it must already be stereo and
     * sample-rate aligned; the caller appends adelay and a label.
     */
    private function sfxChain(string $style, int $index, float $volume): string
    {
        $auto = ['whoosh', 'pop', 'click', 'shutter'];
        if (! in_array($style, ['whoosh', 'pop', 'click', 'shutter'], true)) {
            $style = $auto[$index % count($auto)];
        }
        $gain = $this->number($volume);
        $format = 'aformat=sample_rates=44100:channel_layouts=stereo';

        return match ($style) {
            'pop' => 'sine=frequency=520:sample_rate=44100:duration=0.16'
                .',afade=t=in:st=0:d=0.005,afade=t=out:st=0.02:d=0.14,volume='.$this->number($volume * 2.2)
                .','.$format,
            'click' => 'sine=frequency=1700:sample_rate=44100:duration=0.06'
                .',highpass=f=600,afade=t=out:st=0.005:d=0.055,volume='.$this->number($volume * 1.8)
                .','.$format,
            'shutter' => 'anoisesrc=color=white:sample_rate=44100:amplitude=0.5:d=0.09'
                .',highpass=f=1200,lowpass=f=9000,afade=t=out:st=0.01:d=0.08,volume='.$gain
                .','.$format,
            default => 'anoisesrc=color=pink:sample_rate=44100:amplitude=0.12:d=0.42'
                .',highpass=f=400,lowpass=f=4200,afade=t=in:st=0:d=0.05,afade=t=out:st=0.06:d=0.36,volume='.$gain
                .','.$format,
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

    private function textSource(mixed $value): string
    {
        if (is_string($value) && $value !== '' && is_file($value)) {
            return trim((string) file_get_contents($value));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return list<string> */
    private function textSources(mixed $values): array
    {
        $texts = [];
        foreach ((array) $values as $value) {
            $text = $this->textSource($value);
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    private function fontsDirectoryOption(string $font): string
    {
        return is_file($font)
            ? ':fontsdir='.$this->filterValue(dirname($font))
            : '';
    }

    /**
     * Resolve the configured Arabic font family name to an absolute .ttf path.
     * Falls back to the bundled NotoKufiArabic.ttf if the name is not mapped.
     */
    private function resolveFontPath(): string
    {
        $family = trim((string) config('studio.engine.arabic_font', 'Noto Kufi Arabic'));
        $fonts = config('studio.engine.arabic_fonts', []);
        $fontsDir = trim((string) config('studio.engine.fontsdir', ''));

        $file = $fonts[$family] ?? ($fonts['Noto Kufi Arabic'] ?? 'NotoKufiArabic.ttf');

        if ($fontsDir !== '' && is_file($fontsDir.'/'.$file)) {
            return $fontsDir.'/'.$file;
        }

        $fallback = base_path('../python_engine/fonts/'.$file);
        if (is_file($fallback)) {
            return $fallback;
        }

        return $file;
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

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
