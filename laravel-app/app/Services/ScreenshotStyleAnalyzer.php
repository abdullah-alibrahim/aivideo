<?php

namespace App\Services;

use RuntimeException;

/**
 * Reads a reference screenshot (a frame grabbed from a reel) with Gemini and
 * returns both a reusable script and a sanitised visual design profile.
 */
class ScreenshotStyleAnalyzer
{
    private const TEMPLATES = ['classic', 'clean_news', 'cinematic', 'quote_card', 'bold_stripe'];

    private const SUBTITLE_PRESETS = ['classic', 'hormozi', 'karaoke', 'minimal', 'news'];

    private const TEXT_EFFECTS = ['none', 'fade_in', 'slide_up', 'pop_up'];

    private const CAMERA_MOTIONS = ['static', 'zoom_in', 'pan', 'parallax', 'zoom_out', 'drift', 'tilt_up', 'handheld'];

    private const MOTION_PRESETS = ['custom', 'subtle_story', 'cinematic_depth', 'news_wipe', 'hook_focus', 'minimal', 'dynamic'];

    private const SCENE_TRANSITIONS = ['template', 'fade', 'wipe', 'slide', 'varied', 'zoom_blur', 'glitch', 'whip_pan', 'auto'];

    private const COLOR_GRADES = ['none', 'cinematic', 'warm', 'cool', 'vivid', 'mono'];

    private const MUSIC_MOODS = ['auto', 'epic', 'reflective', 'energetic', 'calm'];

    private const SFX_STYLES = ['auto', 'whoosh', 'pop', 'click', 'shutter'];

    public function __construct(private readonly GeminiContentService $gemini) {}

    /**
     * @param  list<string>  $backgroundIds
     * @return array<string, mixed>
     */
    public function analyze(string $imagePath, string $language, int $sceneCount, array $backgroundIds, string $direction = ''): array
    {
        $sceneCount = max(1, $sceneCount);
        $response = $this->gemini->generateFromImage(
            $this->prompt($language, $sceneCount, $backgroundIds, $direction),
            $imagePath,
        );

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        if ($data === []) {
            throw new RuntimeException('Gemini returned an empty reference analysis.');
        }

        return $this->normalize($data, $sceneCount, $backgroundIds) + [
            'ai_model' => is_string($response['model'] ?? null) ? $response['model'] : null,
        ];
    }

    /**
     * @param  list<string>  $backgroundIds
     */
    private function prompt(string $language, int $sceneCount, array $backgroundIds, string $direction): string
    {
        $languageName = $language === 'ar' ? 'Arabic' : 'English';
        $backgroundList = $backgroundIds === [] ? 'none' : implode(', ', $backgroundIds);
        $direction = trim($direction);

        return 'You are a short-form video art director. The attached image is a screenshot taken from a vertical social video. '
            .'Recreate its idea and visual identity as a brand-new reel script that follows the same format and audience. '
            .'Return one JSON object only, with these keys: title, topic, headline, points (exactly '.$sceneCount.' short spoken scenes), cta, caption, hashtags, design. '
            .'design must be an object with: template (one of '.implode(', ', self::TEMPLATES).'), '
            .'subtitle_preset (one of '.implode(', ', self::SUBTITLE_PRESETS).'), '
            .'accent_color (#RRGGBB sampled from the dominant accent in the reference), '
            .'background_dimming (integer 0-80), font_scale (integer 80-130), '
            .'text_effect (one of '.implode(', ', self::TEXT_EFFECTS).'), '
            .'camera_motion (one of '.implode(', ', self::CAMERA_MOTIONS).'), '
            .'motion_preset (one of '.implode(', ', self::MOTION_PRESETS).'), '
            .'scene_transition (one of '.implode(', ', self::SCENE_TRANSITIONS).'), '
            .'color_grade (one of '.implode(', ', self::COLOR_GRADES).'), '
            .'overlay_grain (boolean), overlay_light_leak (boolean), overlay_dust (boolean), '
            .'emoji_auto (boolean), hook_word_by_word (boolean), highlight_important (boolean), '
            .'music_mood (one of '.implode(', ', self::MUSIC_MOODS).'), '
            .'sfx_style (one of '.implode(', ', self::SFX_STYLES).'), '
            .'background_id (one of '.$backgroundList.', or an empty string if the frame has its own footage), '
            .'design_notes (one short sentence explaining the visual match). '
            .'All spoken and written text must be in '.$languageName.'. '
            .'Use the screenshot idea, pacing and tone, but write new copy instead of transcribing the frame. '
            .'The headline must be a spoken hook of at most 12 words. Each scene must teach one concrete idea and stay under 120 characters. '
            .'The cta must be a short imperative. Caption is a ready-to-post social caption and hashtags is one space-separated string with 4-7 tags. '
            .'Keep every claim accurate and verifiable.'
            .($direction !== '' ? ' Additional direction from the user: '.$direction : '')
            .' Output only valid JSON.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $backgroundIds
     * @return array<string, mixed>
     */
    private function normalize(array $data, int $sceneCount, array $backgroundIds): array
    {
        $design = is_array($data['design'] ?? null) ? $data['design'] : [];
        $headline = $this->text($data['headline'] ?? null, 255);
        $points = [];
        foreach ((array) ($data['points'] ?? []) as $point) {
            $value = $this->text($point, 500);
            if ($value !== '' && ! in_array($value, $points, true)) {
                $points[] = $value;
            }
            if (count($points) >= $sceneCount) {
                break;
            }
        }

        if ($headline === '' || $points === []) {
            throw new RuntimeException('Gemini did not return a usable reference script.');
        }

        $topic = $this->text($data['topic'] ?? null, 255) ?: $headline;
        $title = $this->text($data['title'] ?? null, 255) ?: $topic;

        return [
            'title' => $title,
            'topic' => $topic,
            'headline' => $headline,
            'points' => $points,
            'cta' => $this->text($data['cta'] ?? null, 255),
            'caption' => $this->text($data['caption'] ?? null, 3000),
            'hashtags' => $this->hashtags($data['hashtags'] ?? null),
            'template' => $this->choice($design['template'] ?? null, self::TEMPLATES, 'cinematic'),
            'subtitle_preset' => $this->choice($design['subtitle_preset'] ?? null, self::SUBTITLE_PRESETS, 'classic'),
            'accent_color' => $this->accentColor($design['accent_color'] ?? null),
            'background_dimming' => $this->clampInt($design['background_dimming'] ?? null, 0, 80, 35),
            'font_scale' => $this->clampInt($design['font_scale'] ?? null, 80, 130, 100),
            'text_effect' => $this->choice($design['text_effect'] ?? null, self::TEXT_EFFECTS, 'slide_up'),
            'camera_motion' => $this->choice($design['camera_motion'] ?? null, self::CAMERA_MOTIONS, 'zoom_in'),
            'motion_preset' => $this->choice($design['motion_preset'] ?? null, self::MOTION_PRESETS, 'cinematic_depth'),
            'scene_transition' => $this->choice($design['scene_transition'] ?? null, self::SCENE_TRANSITIONS, 'auto'),
            'color_grade' => $this->choice($design['color_grade'] ?? null, self::COLOR_GRADES, 'cinematic'),
            'overlay_grain' => $this->boolean($design['overlay_grain'] ?? null, false),
            'overlay_light_leak' => $this->boolean($design['overlay_light_leak'] ?? null, false),
            'overlay_dust' => $this->boolean($design['overlay_dust'] ?? null, false),
            'emoji_auto' => $this->boolean($design['emoji_auto'] ?? null, true),
            'hook_word_by_word' => $this->boolean($design['hook_word_by_word'] ?? null, true),
            'highlight_important' => $this->boolean($design['highlight_important'] ?? null, true),
            'music_mood' => $this->choice($design['music_mood'] ?? null, self::MUSIC_MOODS, 'auto'),
            'sfx_style' => $this->choice($design['sfx_style'] ?? null, self::SFX_STYLES, 'auto'),
            'background_id' => $this->choice($design['background_id'] ?? null, $backgroundIds, ''),
            'design_notes' => $this->text($design['design_notes'] ?? null, 300),
        ];
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed, string $default): string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function boolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) round((float) $value)));
    }

    private function accentColor(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtoupper($value) : '#D6B87A';
    }

    private function text(mixed $value, int $limit): string
    {
        if (is_array($value)) {
            $value = implode(' ', array_filter($value, 'is_scalar'));
        }
        if (! is_scalar($value)) {
            return '';
        }

        return mb_substr(trim((string) $value), 0, $limit, 'UTF-8');
    }

    private function hashtags(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(' ', array_filter($value, 'is_scalar'));
        }

        return $this->text($value, 1000);
    }
}
