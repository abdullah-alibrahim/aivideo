<?php

namespace App\Http\Controllers;

use App\Exceptions\RenderFailedException;
use App\Jobs\GenerateReelVideo;
use App\Models\ProviderSetting;
use App\Models\Reel;
use App\Models\ReelCollection;
use App\Services\BackgroundSequenceSelector;
use App\Services\ElevenLabsVoiceoverService;
use App\Services\FactCheckService;
use App\Services\GeminiContentService;
use App\Services\ProviderKeyRing;
use App\Services\ReelContentGenerator;
use App\Services\ReelQualityAnalyzer;
use App\Services\ReelQualityReviewService;
use App\Services\RenderEngine;
use App\Services\ScreenshotStyleAnalyzer;
use App\Services\VideoScriptPacing;
use App\Support\StudioPaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ReelController extends Controller
{
    public function index(Request $request): View
    {
        $requestedLanguage = $request->query('lang');
        $lang = in_array($requestedLanguage, ['ar', 'en'], true)
            ? $requestedLanguage
            : (in_array(session('app_locale'), ['ar', 'en'], true) ? session('app_locale') : 'ar');
        session(['app_locale' => $lang]);
        app()->setLocale($lang);

        $query = Reel::with('collection')->orderBy('created_at', 'desc');

        if ($request->has('filter_lang') && in_array($request->filter_lang, ['ar', 'en'], true)) {
            $query->where('language', $request->filter_lang);
        }

        if ($request->has('filter_niche') && $request->filter_niche) {
            $query->where('niche', $request->filter_niche);
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(static function ($builder) use ($term): void {
                $builder->where('title', 'like', '%'.$term.'%')
                    ->orWhere('topic', 'like', '%'.$term.'%');
            });
        }

        if (in_array($request->query('filter_status'), ['queued', 'processing', 'completed', 'failed'], true)) {
            $query->where('status', $request->query('filter_status'));
        }

        $reels = $query->get();
        $totalReels = Reel::count();
        $completedReels = Reel::where('status', 'completed')->count();
        $stats = [
            'total' => $totalReels,
            'ar_count' => Reel::where('language', 'ar')->count(),
            'en_count' => Reel::where('language', 'en')->count(),
            'completed' => $completedReels,
            'completion_rate' => $totalReels > 0 ? (int) round(($completedReels / $totalReels) * 100) : 0,
        ];

        $backgrounds = $this->backgroundCatalog();
        $musicTracks = $this->musicTrackCatalog();
        $voices = $this->elevenLabsVoiceCatalog();
        $voiceProfile = $this->voiceProfile();

        return view('reels.index', compact('reels', 'lang', 'stats', 'backgrounds', 'musicTracks', 'voices', 'voiceProfile'));
    }

    public function wizard(Request $request): View
    {
        $requestedLanguage = $request->query('lang');
        $lang = in_array($requestedLanguage, ['ar', 'en'], true)
            ? $requestedLanguage
            : (in_array(session('app_locale'), ['ar', 'en'], true) ? session('app_locale') : 'ar');
        session(['app_locale' => $lang]);
        app()->setLocale($lang);

        $backgrounds = $this->backgroundCatalog();
        $musicTracks = $this->musicTrackCatalog();
        $voices = $this->elevenLabsVoiceCatalog();
        $voiceProfile = $this->voiceProfile();
        $elevenLabsKeys = $this->configuredElevenLabsKeys();
        $voiceoverAvailable = [
            'ar' => $elevenLabsKeys !== [] && filled($this->elevenLabsVoiceId('ar')),
            'en' => $elevenLabsKeys !== [] && filled($this->elevenLabsVoiceId('en')),
        ];

        return view('reels.wizard', compact('lang', 'backgrounds', 'voiceoverAvailable', 'musicTracks', 'voices', 'voiceProfile'));
    }

    public function show(Reel $reel): RedirectResponse
    {
        $lang = in_array(session('app_locale'), ['ar', 'en'], true) ? session('app_locale') : 'ar';

        return redirect()->route('reels.index', [
            'active' => $reel->id,
            'lang' => $lang,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'headline' => ['nullable', 'string', 'max:255'],
            'cta' => ['nullable', 'string', 'max:255'],
            'line1' => ['nullable', 'string', 'max:500'],
            'line2' => ['nullable', 'string', 'max:500'],
            'line3' => ['nullable', 'string', 'max:500'],
            'custom_points' => ['nullable', 'array', 'max:10'],
            'custom_points.*' => ['nullable', 'string', 'max:500'],
            'cta' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'template' => ['nullable', Rule::in(['classic', 'clean_news', 'cinematic', 'quote_card', 'bold_stripe', 'numbered_list'])],
            'handle' => ['nullable', 'string', 'max:100'],
            'accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'font_scale' => ['nullable', 'integer', 'min:80', 'max:130'],
            'font_family' => ['nullable', Rule::in(array_keys(config('studio.engine.arabic_fonts', [])))],
            'text_effect' => ['nullable', Rule::in(['none', 'fade_in', 'slide_up', 'pop_up'])],
            'subtitle_preset' => ['nullable', Rule::in(['classic', 'hormozi', 'karaoke', 'minimal', 'news'])],
            'emoji_auto' => ['nullable', 'boolean'],
            'timeline_json' => ['nullable', 'string', 'max:8000'],
            'quality' => ['nullable', Rule::in(['720', '1080'])],
            'captions' => ['nullable', 'boolean'],
            'auto_music' => ['nullable', 'boolean'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
        ]);

        $language = $validated['language'] ?? 'ar';
        $headline = trim((string) ($validated['headline'] ?? ''));
        if (isset($validated['custom_points'])) {
            $points = array_values(array_filter(array_map(
                static fn (mixed $point): string => is_string($point) ? trim($point) : '',
                $validated['custom_points'],
            ), static fn (string $point): bool => $point !== ''));
        } else {
            $points = [];
            foreach (['line1', 'line2', 'line3'] as $field) {
                $value = trim((string) ($validated[$field] ?? ''));
                if ($value !== '') {
                    $points[] = $value;
                }
            }
        }

        $template = (string) ($validated['template'] ?? 'classic');
        $handle = trim((string) ($validated['handle'] ?? ''));
        $accentColor = (string) ($validated['accent_color'] ?? '#fb7185');
        $fontScale = (int) ($validated['font_scale'] ?? 100);
        $textEffect = (string) ($validated['text_effect'] ?? 'slide_up');
        $quality = (int) ($validated['quality'] ?? 1080);
        $aspectRatio = (string) ($validated['aspect_ratio'] ?? '9:16');

        $renderer = app(RenderEngine::class);

        Storage::disk('public')->makeDirectory('previews');
        foreach (Storage::disk('public')->files('previews') as $oldPreview) {
            if (Storage::disk('public')->lastModified($oldPreview) < now()->subHour()->timestamp) {
                Storage::disk('public')->delete($oldPreview);
            }
        }

        $relativePath = 'previews/'.Str::uuid().'.png';
        $absolutePath = storage_path('app/public/'.$relativePath);
        @mkdir(dirname($absolutePath), 0755, true);

        try {
            $renderer->renderPreview([
                'headline' => $headline,
                'cta' => trim((string) ($validated['cta'] ?? '')),
                'custom_points' => $points,
                'handle' => $handle,
                'language' => $language,
                'accent_color' => $accentColor,
                'font_scale' => $fontScale,
                'font_family' => (string) ($validated['font_family'] ?? 'Tajawal'),
                'text_effect' => $textEffect,
                'subtitle_preset' => (string) ($validated['subtitle_preset'] ?? 'classic'),
                'emoji_auto' => $request->boolean('emoji_auto'),
                'timeline' => $this->parseTimeline($validated['timeline_json'] ?? null, 10.0),
                'template' => $template,
                'quality' => (string) $quality,
                'aspect_ratio' => $aspectRatio,
                'captions' => false,
            ], $absolutePath);
        } catch (\Throwable $exception) {
            Log::error('Preview render failed', ['error' => $exception->getMessage()]);
        }

        if (! is_file($absolutePath) || (filesize($absolutePath) ?: 0) < 8) {
            @unlink($absolutePath);

            return response()->json([
                'status' => 'error',
                'message' => $this->interfaceLanguage() === 'en' ? 'Preview render failed.' : 'فشل إنشاء معاينة الفيديو.',
            ], 502);
        }

        return response()->json([
            'status' => 'success',
            'url' => asset('storage/'.$relativePath),
        ]);
    }

    /**
     * Render a short 480p sample video with the current styling so the user can
     * check motion and captions before starting the full render.
     */
    public function previewVideo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'headline' => ['nullable', 'string', 'max:255'],
            'cta' => ['nullable', 'string', 'max:255'],
            'line1' => ['nullable', 'string', 'max:500'],
            'line2' => ['nullable', 'string', 'max:500'],
            'line3' => ['nullable', 'string', 'max:500'],
            'custom_points' => ['nullable', 'array', 'max:10'],
            'custom_points.*' => ['nullable', 'string', 'max:500'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'template' => ['nullable', Rule::in(['classic', 'clean_news', 'cinematic', 'quote_card', 'bold_stripe', 'numbered_list'])],
            'handle' => ['nullable', 'string', 'max:100'],
            'accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'font_scale' => ['nullable', 'integer', 'min:80', 'max:130'],
            'font_family' => ['nullable', Rule::in(array_keys(config('studio.engine.arabic_fonts', [])))],
            'text_effect' => ['nullable', Rule::in(['none', 'fade_in', 'slide_up', 'pop_up'])],
            'subtitle_preset' => ['nullable', Rule::in(['classic', 'hormozi', 'karaoke', 'minimal', 'news'])],
            'emoji_auto' => ['nullable', 'boolean'],
            'timeline_json' => ['nullable', 'string', 'max:8000'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'motion_preset' => ['nullable', Rule::in(['custom', 'subtle_story', 'cinematic_depth', 'news_wipe', 'hook_focus', 'minimal', 'dynamic'])],
            'camera_motion' => ['nullable', Rule::in(['static', 'zoom_in', 'pan', 'parallax', 'zoom_out', 'drift', 'tilt_up', 'handheld'])],
            'scene_transition' => ['nullable', Rule::in(['template', 'fade', 'wipe', 'slide', 'varied', 'zoom_blur', 'glitch', 'whip_pan', 'auto'])],
            'color_grade' => ['nullable', Rule::in(['none', 'cinematic', 'warm', 'cool', 'vivid', 'mono'])],
            'background_dimming' => ['nullable', 'integer', 'min:0', 'max:80'],
            ...$this->voiceRules(),
        ]);

        $language = (string) ($validated['language'] ?? 'ar');
        $headline = trim((string) ($validated['headline'] ?? ''));
        $points = [];
        if (isset($validated['custom_points'])) {
            $points = array_values(array_filter(array_map(
                static fn (mixed $point): string => is_string($point) ? trim($point) : '',
                $validated['custom_points'],
            ), static fn (string $point): bool => $point !== ''));
        } else {
            foreach (['line1', 'line2', 'line3'] as $field) {
                $value = trim((string) ($validated[$field] ?? ''));
                if ($value !== '') {
                    $points[] = $value;
                }
            }
        }

        if ($headline === '' && $points === []) {
            return response()->json([
                'status' => 'error',
                'message' => $language === 'en' ? 'Add a headline or at least one scene line first.' : 'أضف عنواناً أو سطر مشهد واحد على الأقل أولاً.',
            ], 422);
        }

        $voiceoverEnabled = $request->boolean('voiceover');
        $selectedVoiceId = trim((string) ($validated['voice_id'] ?? ''));
        if ($voiceoverEnabled && ! $this->voiceoverReady($language, $selectedVoiceId)) {
            return response()->json([
                'status' => 'error',
                'message' => $language === 'en'
                    ? 'Add an active ElevenLabs key and pick a voice before previewing narration.'
                    : 'أضف مفتاح ElevenLabs نشطاً واختر صوتاً قبل معاينة التعليق الصوتي.',
            ], 422);
        }

        Storage::disk('public')->makeDirectory('previews');
        foreach (Storage::disk('public')->files('previews') as $oldPreview) {
            if (Storage::disk('public')->lastModified($oldPreview) < now()->subHour()->timestamp) {
                Storage::disk('public')->delete($oldPreview);
            }
        }

        $relativePath = 'previews/'.Str::uuid().'.mp4';
        $absolutePath = Storage::disk('public')->path($relativePath);
        @mkdir(dirname($absolutePath), 0755, true);

        $options = [
            'headline' => $headline,
            'custom_points' => $points,
            'cta' => trim((string) ($validated['cta'] ?? '')),
            'handle' => trim((string) ($validated['handle'] ?? '')),
            'language' => $language,
            'template' => (string) ($validated['template'] ?? 'classic'),
            'aspect_ratio' => (string) ($validated['aspect_ratio'] ?? '9:16'),
            'accent_color' => (string) ($validated['accent_color'] ?? '#fb7185'),
            'font_scale' => (int) ($validated['font_scale'] ?? 100),
            'font_family' => (string) ($validated['font_family'] ?? 'Tajawal'),
            'text_effect' => (string) ($validated['text_effect'] ?? 'slide_up'),
            'subtitle_preset' => (string) ($validated['subtitle_preset'] ?? 'classic'),
            'emoji_auto' => $request->boolean('emoji_auto'),
            'background_dimming' => (int) ($validated['background_dimming'] ?? 35),
            'motion_preset' => (string) ($validated['motion_preset'] ?? 'custom'),
            'camera_motion' => (string) ($validated['camera_motion'] ?? 'zoom_in'),
            'scene_transition' => (string) ($validated['scene_transition'] ?? 'template'),
            'color_grade' => (string) ($validated['color_grade'] ?? 'none'),
            'hook_word_by_word' => true,
            'highlight_important' => true,
            'timeline' => $this->parseTimeline($validated['timeline_json'] ?? null, 8.0),
            ...$this->voiceOptions($validated, $voiceoverEnabled, $selectedVoiceId),
        ];

        try {
            app(RenderEngine::class)->renderDraftVideo($options, $absolutePath);
        } catch (\Throwable $exception) {
            Log::warning('Draft preview render failed', ['exception_type' => $exception::class]);
            @unlink($absolutePath);

            $message = $exception instanceof RenderFailedException && $exception->userMessage !== ''
                ? $exception->userMessage
                : ($language === 'en' ? 'Draft preview failed.' : 'فشل إنشاء المعاينة السريعة.');

            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], 502);
        }

        if (! is_file($absolutePath) || (filesize($absolutePath) ?: 0) < 1) {
            @unlink($absolutePath);

            return response()->json([
                'status' => 'error',
                'message' => $language === 'en' ? 'Draft preview failed.' : 'فشل إنشاء المعاينة السريعة.',
            ], 502);
        }

        return response()->json([
            'status' => 'success',
            'url' => asset('storage/'.$relativePath),
        ]);
    }

    public function saveVoiceProfile(Request $request): JsonResponse
    {
        $validated = $request->validate($this->voiceRules());
        $user = $request->user();
        if ($user === null) {
            abort(403);
        }

        $options = $this->voiceOptions($validated, true, trim((string) ($validated['voice_id'] ?? '')));
        $settings = is_array($user->getAttribute('settings')) ? $user->getAttribute('settings') : [];
        $settings['voice_profile'] = array_filter(array_merge([
            'voiceover_tone' => $options['voiceover_tone'],
            'voice_id' => $options['voice_id'],
        ], $options['voice_settings']), static fn (mixed $value): bool => $value !== null && $value !== '');
        $user->forceFill(['settings' => $settings])->save();

        return response()->json(['status' => 'success']);
    }

    public function qualityCheck(
        Request $request,
        ReelQualityAnalyzer $analyzer,
        ReelQualityReviewService $reviewer,
    ): JsonResponse {
        $input = $request->validate([
            'headline' => ['nullable', 'string', 'max:255'],
            'hook_headline' => ['nullable', 'string', 'max:255'],
            'line1' => ['nullable', 'string', 'max:500'],
            'line2' => ['nullable', 'string', 'max:500'],
            'line3' => ['nullable', 'string', 'max:500'],
            'custom_points' => ['nullable', 'array', 'max:10'],
            'custom_points.*' => ['nullable', 'string', 'max:500'],
            'cta' => ['nullable', 'string', 'max:255'],
            'topic' => ['nullable', 'string', 'max:255'],
            'user_prompt' => ['nullable', 'string', 'max:1000'],
            'manual_content' => ['nullable', 'boolean'],
            'duration' => ['nullable', 'integer', 'min:10', 'max:60'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'timeline_json' => ['nullable', 'string', 'max:8000'],
        ]);
        $input['custom_points'] = isset($input['custom_points'])
            ? array_values(array_filter(array_map(
                static fn (mixed $point): string => is_string($point) ? trim($point) : '',
                $input['custom_points'],
            ), static fn (string $point): bool => $point !== ''))
            : array_values(array_filter([
                $input['line1'] ?? null,
                $input['line2'] ?? null,
                $input['line3'] ?? null,
            ], static fn ($line): bool => is_string($line) && trim($line) !== ''));
        $input['cta'] = trim((string) ($input['cta'] ?? ''));
        $input['timeline'] = $this->parseTimeline(
            (string) ($input['timeline_json'] ?? ''),
            (float) ($input['duration'] ?? 10),
        );
        $content = array_merge(
            [
                $input['headline'] ?? '',
                $input['hook_headline'] ?? '',
                $input['topic'] ?? '',
                $input['user_prompt'] ?? '',
                ...$input['custom_points'],
                $input['cta'],
            ],
            array_column($input['timeline'], 'text'),
        );
        if (! collect($content)->contains(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')) {
            $uiLanguage = $this->interfaceLanguage();

            return response()->json([
                'status' => 'error',
                'message' => $uiLanguage === 'en' ? 'Add script content before requesting an AI review.' : 'أضف نص الفيديو قبل طلب التقييم بالذكاء الاصطناعي.',
            ], 422);
        }

        try {
            $report = $analyzer->analyze($input);
            $report['semantic_review'] = $reviewer->review($input);

            return response()->json(['status' => 'success', 'report' => $report]);
        } catch (\RuntimeException $exception) {
            Log::warning('AI reel quality review failed', ['exception_type' => $exception::class]);
            $uiLanguage = $this->interfaceLanguage();

            return response()->json([
                'status' => 'error',
                'message' => $uiLanguage === 'en'
                    ? 'The AI quality review is unavailable. Check the Gemini key and try again.'
                    : 'تعذّر التقييم بالذكاء الاصطناعي. تحقق من مفتاح Gemini وحاول مجددًا.',
            ], 502);
        }
    }

    public function factCheck(Request $request, FactCheckService $factChecker): JsonResponse
    {
        $input = $request->validate([
            'headline' => ['nullable', 'string', 'max:255'],
            'topic' => ['nullable', 'string', 'max:255'],
            'script' => ['nullable', 'string', 'max:5000'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        if (trim((string) ($input['script'] ?? '').($input['headline'] ?? '').($input['topic'] ?? '')) === '') {
            $uiLanguage = $this->interfaceLanguage();

            return response()->json([
                'status' => 'error',
                'message' => $uiLanguage === 'en' ? 'Add a headline or script before checking.' : 'أضف الهوك أو النص قبل فحص الحقائق.',
            ], 422);
        }

        try {
            return response()->json(['status' => 'success', 'report' => $factChecker->check($input)]);
        } catch (\Throwable $exception) {
            return $this->geminiFailure($exception);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $backgrounds = $this->backgroundCatalog();
        $backgroundIds = array_column($backgrounds, 'id');

        $validated = $request->validate([
            'topic' => ['nullable', 'string', 'max:255'],
            'user_prompt' => ['nullable', 'string', 'max:1000'],
            'selected_idea' => ['nullable', 'string', 'max:255'],
            'selected_idea_description' => ['nullable', 'string', 'max:1000'],
            'selected_idea_angle' => ['nullable', 'string', 'max:500'],
            'manual_content' => ['nullable', 'boolean'],
            'generation_mode' => ['nullable', Rule::in(['single', 'variants', 'batch'])],
            'variant_count' => ['nullable', 'integer', 'min:3', 'max:5'],
            'batch_topics' => ['nullable', 'string', 'max:1500'],
            'title' => ['nullable', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:100'],
            'niche' => ['nullable', 'string', Rule::in(['money', 'habits', 'psychology', 'tech', 'business', 'productivity'])],
            'target_country' => ['nullable', Rule::in(['US', 'UK', 'Canada', 'Australia', 'Global'])],
            'goal' => ['nullable', 'string', Rule::in(['followers', 'saves', 'shares', 'comments'])],
            'template' => ['nullable', 'string', Rule::in(['classic', 'clean_news', 'cinematic', 'quote_card', 'bold_stripe', 'numbered_list'])],
            'format_type' => ['nullable', Rule::in(['full_idea', 'quick_tip', 'list', 'story'])],
            'accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'style' => ['nullable', 'string', 'max:100'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'quality' => ['nullable', Rule::in(['720', '1080'])],
            'captions' => ['nullable', 'boolean'],
            'auto_music' => ['nullable', 'boolean'],
            'camera_motion' => ['nullable', Rule::in(['static', 'zoom_in', 'pan', 'parallax', 'zoom_out', 'drift', 'tilt_up', 'handheld'])],
            'text_motion' => ['nullable', Rule::in(['vertical', 'slide_right', 'slide_left'])],
            'scene_transition' => ['nullable', Rule::in(['template', 'fade', 'wipe', 'slide', 'varied', 'zoom_blur', 'glitch', 'whip_pan', 'auto'])],
            'color_grade' => ['nullable', Rule::in(['none', 'cinematic', 'warm', 'cool', 'vivid', 'mono'])],
            'subtitle_preset' => ['nullable', Rule::in(['classic', 'hormozi', 'karaoke', 'minimal', 'news'])],
            'emoji_auto' => ['nullable', 'boolean'],
            'overlay_grain' => ['nullable', 'boolean'],
            'overlay_light_leak' => ['nullable', 'boolean'],
            'overlay_dust' => ['nullable', 'boolean'],
            'music_mood' => ['nullable', Rule::in(['auto', 'epic', 'reflective', 'energetic', 'calm'])],
            'sfx_style' => ['nullable', Rule::in(['auto', 'whoosh', 'pop', 'click', 'shutter'])],
            'timeline_json' => ['nullable', 'string', 'max:8000'],
            'motion_preset' => ['nullable', Rule::in(['custom', 'subtle_story', 'cinematic_depth', 'news_wipe', 'hook_focus', 'minimal', 'dynamic'])],
            'hook_word_by_word' => ['nullable', 'boolean'],
            'highlight_important' => ['nullable', 'boolean'],
            'font_scale' => ['nullable', 'integer', 'min:80', 'max:130'],
            'font_family' => ['nullable', Rule::in(array_keys(config('studio.engine.arabic_fonts', [])))],
            'text_effect' => ['nullable', Rule::in(['none', 'fade_in', 'slide_up', 'pop_up'])],
            'background_dimming' => ['nullable', 'integer', 'min:0', 'max:80'],
            'duration' => ['nullable', 'integer', 'min:10', 'max:60'],
            'gemini_keys' => ['nullable', 'string', 'max:5000'],
            'videos' => ['nullable', 'array', 'max:5'],
            'videos.*' => ['file', 'mimes:mp4,mov,avi,webm', 'max:'.((int) config('studio.limits.max_video_mb', 100) * 1024)],
            'bg_preset' => ['nullable', Rule::in($backgroundIds)],
            'music' => ['nullable', 'file', 'mimes:mp3,wav,m4a', 'max:'.((int) config('studio.limits.max_music_mb', 20) * 1024)],
            'music_track' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            ...$this->voiceRules(),
            'sound_effects_enabled' => ['nullable', 'boolean'],
            'headline' => ['nullable', 'string', 'max:255'],
            'hook_headline' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:3000'],
            'hashtags' => ['nullable', 'string', 'max:1000'],
            'cta' => ['nullable', 'string', 'max:255'],
            'line1' => ['nullable', 'string', 'max:500'],
            'line2' => ['nullable', 'string', 'max:500'],
            'line3' => ['nullable', 'string', 'max:500'],
            'custom_points' => ['nullable', 'array', 'max:10'],
            'custom_points.*' => ['nullable', 'string', 'max:500'],
            'custom_points_json' => ['nullable', 'json', 'max:6000'],
        ]);
        $uiLanguage = $this->interfaceLanguage();

        $userPrompt = trim((string) ($validated['user_prompt'] ?? '')) ?: null;
        $ideaContext = array_filter([
            trim((string) ($validated['selected_idea'] ?? '')) !== '' ? 'Selected idea: '.trim((string) $validated['selected_idea']) : null,
            trim((string) ($validated['selected_idea_description'] ?? '')) !== '' ? 'Idea description: '.trim((string) $validated['selected_idea_description']) : null,
            trim((string) ($validated['selected_idea_angle'] ?? '')) !== '' ? 'Idea angle: '.trim((string) $validated['selected_idea_angle']) : null,
        ]);
        if ($ideaContext !== []) {
            $userPrompt = trim(implode("\n", array_filter([$userPrompt, implode("\n", $ideaContext)]))) ?: null;
        }
        $manualContent = $request->boolean('manual_content');
        $generationMode = $validated['generation_mode'] ?? 'single';
        $batchTopics = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            preg_split('/\R/u', (string) ($validated['batch_topics'] ?? '')) ?: [],
        ), static fn (string $value): bool => $value !== ''));

        if ($generationMode === 'batch' && (count($batchTopics) < 2 || count($batchTopics) > 5)) {
            $message = $uiLanguage === 'en'
                ? 'A batch needs between 2 and 5 non-empty topics, one per line.'
                : 'تتطلب الدفعة موضوعين إلى خمسة مواضيع غير فارغة، موضوعاً واحداً في كل سطر.';

            return back()->withErrors(['batch_topics' => $message])->withInput();
        }

        if ($generationMode === 'batch' && collect($batchTopics)->contains(static fn (string $value): bool => mb_strlen($value, 'UTF-8') > 255)) {
            $message = $uiLanguage === 'en'
                ? 'Each batch topic must be 255 characters or fewer.'
                : 'يجب ألا يتجاوز كل موضوع في الدفعة 255 حرفاً.';

            return back()->withErrors(['batch_topics' => $message])->withInput();
        }

        if ($generationMode !== 'single' && $manualContent) {
            $message = $uiLanguage === 'en'
                ? 'Multiple generation requires AI mode so each item can receive distinct content.'
                : 'يتطلب التوليد المتعدد وضع الذكاء الاصطناعي كي يحصل كل فيديو على محتوى مختلف.';

            return back()->withErrors(['generation_mode' => $message])->withInput();
        }

        $customHeadline = trim((string) ($validated['headline'] ?? $validated['hook_headline'] ?? ''));
        $customPointsJson = trim((string) ($validated['custom_points_json'] ?? ''));
        if ($customPointsJson !== '') {
            $decodedPoints = json_decode($customPointsJson, true);
            if (! is_array($decodedPoints) || ! array_is_list($decodedPoints) || count($decodedPoints) > 10) {
                return back()->withErrors(['custom_points_json' => $uiLanguage === 'en'
                    ? 'The script must contain between 1 and 10 valid scenes.'
                    : 'يجب أن يحتوي النص على مشهد واحد إلى عشرة مشاهد صالحة.'])->withInput();
            }
            foreach ($decodedPoints as $point) {
                if (! is_string($point) || trim($point) === '' || mb_strlen($point, 'UTF-8') > 500) {
                    return back()->withErrors(['custom_points_json' => $uiLanguage === 'en'
                        ? 'Each script scene must contain 1 to 500 characters.'
                        : 'يجب أن يحتوي كل مشهد على حرف واحد إلى 500 حرف.'])->withInput();
                }
            }
            $customPoints = array_values(array_map(static fn (string $point): string => trim($point), $decodedPoints));
        } else {
            $customPoints = [];
            for ($index = 1; $index <= 3; $index++) {
                $value = $validated['line'.$index] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $customPoints[] = trim($value);
                }
            }
            foreach ($validated['custom_points'] ?? [] as $point) {
                if (is_string($point) && trim($point) !== '') {
                    $customPoints[] = trim($point);
                }
            }
        }

        if ($manualContent && ($customHeadline === '' || $customPoints === [])) {
            $message = $uiLanguage === 'en'
                ? 'Manual mode needs a headline and at least one script line.'
                : 'يتطلب الوضع اليدوي عنواناً وسطر نص واحداً على الأقل.';

            return back()->withErrors(['manual_content' => $message])->withInput();
        }

        $topic = $generationMode === 'batch'
            ? $batchTopics[0]
            : (trim((string) ($validated['selected_idea'] ?? '')) ?: trim((string) ($validated['topic'] ?? '')));
        $topic = $topic ?: ($manualContent ? $customHeadline : Str::limit((string) $userPrompt, 50, ''));

        if ($topic === '') {
            $message = $uiLanguage === 'en'
                ? 'Enter a topic or a video brief before generating.'
                : 'أدخل موضوع الفيديو أو وصفه قبل التوليد.';

            return back()->withErrors(['topic' => $message])->withInput();
        }
        $language = $validated['language'] ?? $uiLanguage;
        $voiceoverEnabled = $request->boolean('voiceover_enabled');
        $selectedVoiceId = trim((string) ($validated['voice_id'] ?? ''));

        if ($voiceoverEnabled && ! $this->voiceoverReady($language, $selectedVoiceId)) {
            $message = $uiLanguage === 'en'
                ? 'Add an active ElevenLabs key and a Voice ID for the selected video language in Provider Key Manager to enable narration.'
                : 'أضف مفتاح ElevenLabs نشطاً وVoice ID للغة الفيديو المحددة من شاشة إدارة المفاتيح لتفعيل التعليق الصوتي.';

            return back()->withErrors(['voiceover_enabled' => $message])->withInput();
        }

        $niche = $validated['niche'] ?? 'productivity';
        $targetCountry = $validated['target_country'] ?? 'Global';
        $goal = $validated['goal'] ?? 'followers';
        $template = $validated['template'] ?? 'cinematic';
        $formatType = $validated['format_type'] ?? 'full_idea';
        $handle = trim((string) ($validated['handle'] ?? ''));
        $style = $validated['style'] ?? 'engaging';
        $aspectRatio = $validated['aspect_ratio'] ?? '9:16';
        $cameraMotion = $validated['camera_motion'] ?? 'static';
        $fontScale = (int) ($validated['font_scale'] ?? 100);
        $textEffect = (string) ($validated['text_effect'] ?? 'slide_up');
        $backgroundDimming = (int) ($validated['background_dimming'] ?? 35);
        $antiBorder = $request->boolean('anti_border');
        $accentColor = $validated['accent_color'] ?? '#D6B87A';
        $title = ($validated['title'] ?? '') ?: (($language === 'en' ? 'Reel: ' : 'ريلز: ').$topic);
        $duration = (float) ($validated['duration'] ?? 60.0);
        $configuredGeminiKeys = json_decode((string) config('services.gemini.keys_json', '[]'), true);
        $configuredGeminiKeys = is_array($configuredGeminiKeys) ? $configuredGeminiKeys : [];
        $submittedGeminiKeys = array_map('trim', preg_split('/\r?\n/', (string) ($validated['gemini_keys'] ?? '')));
        $geminiKeys = array_values(array_unique(array_filter(
            array_merge($this->activeProviderKeys('gemini'), $configuredGeminiKeys, $submittedGeminiKeys),
            static fn ($key): bool => is_string($key) && trim($key) !== ''
        )));

        if (! $manualContent && $geminiKeys === []) {
            $message = $uiLanguage === 'en'
                ? 'Add an active Gemini API key before generating content. Local generation is disabled.'
                : 'أضف مفتاح Gemini API مفعّلاً قبل التوليد. التوليد المحلي معطّل.';

            return back()->withErrors(['gemini_keys' => $message])->withInput();
        }

        $localDisk = Storage::disk('local');
        $uploadedVideoPaths = [];
        $storedInputPaths = [];
        $backgroundId = $request->input('bg_preset') ?: ($backgrounds[0]['id'] ?? null);
        $backgroundSequence = false;

        if ($request->hasFile('videos') && count($request->file('videos')) > 0) {
            foreach ($request->file('videos') as $file) {
                $path = $file->store('input_videos', 'local');
                if ($path) {
                    $storedInputPaths[] = $path;
                    $uploadedVideoPaths[] = $localDisk->path($path);
                }
            }
        } else {
            $backgroundPaths = app(BackgroundSequenceSelector::class)->select($backgrounds, $backgroundId, $duration);
            if ($backgroundPaths !== []) {
                $uploadedVideoPaths = $backgroundPaths;
                $backgroundSequence = count($backgroundPaths) > 1;
            } else {
                $sampleSource = base_path('../python_engine/storage/input/sample.mp4');
                if (is_file($sampleSource)) {
                    $uploadedVideoPaths[] = $sampleSource;
                }
            }
        }

        if ($uploadedVideoPaths === []) {
            return back()->with('error', $uiLanguage === 'en' ? 'A source video is required.' : 'يجب توفر مقطع فيديو للمعالجة.');
        }

        $musicPath = null;
        $storedMusicPath = null;
        if ($request->hasFile('music')) {
            $storedMusicPath = $request->file('music')->store('bg_music', 'local');
            if ($storedMusicPath) {
                $musicPath = $localDisk->path($storedMusicPath);
            }
        } elseif (filled($validated['music_track'] ?? null)
            && in_array($validated['music_track'], array_column($this->musicTrackCatalog(), 'path'), true)) {
            $storedMusicPath = (string) $validated['music_track'];
            $musicPath = Storage::disk('public')->path($storedMusicPath);
        }

        $logoAbsolutePath = null;
        if ($request->hasFile('logo')) {
            $storedLogoPath = $request->file('logo')->store('branding', 'local');
            if ($storedLogoPath) {
                $logoAbsolutePath = $localDisk->path($storedLogoPath);
            }
        }

        $options = [
            'font_scale' => $fontScale,
            'font_family' => (string) ($validated['font_family'] ?? 'Tajawal'),
            'text_effect' => $textEffect,
            'background_dimming' => $backgroundDimming,
            'anti_border' => $antiBorder,
            ...$this->voiceOptions($validated, $voiceoverEnabled, $selectedVoiceId),
            'sound_effects' => $request->boolean('sound_effects_enabled'),
            'sfx_style' => $validated['sfx_style'] ?? 'auto',
            'accent_color' => $accentColor,
            'quality' => (int) ($validated['quality'] ?? 1080),
            'captions' => $request->has('captions') ? $request->boolean('captions') : true,
            'auto_music' => $request->has('auto_music') ? $request->boolean('auto_music') : true,
            'music_mood' => $validated['music_mood'] ?? 'auto',
            'manual_content' => $manualContent,
            'motion_preset' => $validated['motion_preset'] ?? 'minimal',
            'text_motion' => $validated['text_motion'] ?? 'vertical',
            'scene_transition' => $validated['scene_transition'] ?? 'template',
            'color_grade' => $validated['color_grade'] ?? 'none',
            'subtitle_preset' => $validated['subtitle_preset'] ?? 'classic',
            'emoji_auto' => $request->boolean('emoji_auto'),
            'overlay_grain' => $request->boolean('overlay_grain'),
            'overlay_light_leak' => $request->boolean('overlay_light_leak'),
            'overlay_dust' => $request->boolean('overlay_dust'),
            'hook_word_by_word' => $request->boolean('hook_word_by_word'),
            'highlight_important' => $request->has('highlight_important')
                ? $request->boolean('highlight_important')
                : true,
            'bg_preset' => $backgroundId,
            'background_sequence' => $backgroundSequence,
            'custom_headline' => $customHeadline,
            'custom_points' => $customPoints,
            'cta' => trim((string) ($validated['cta'] ?? '')),
            'caption' => trim((string) ($validated['caption'] ?? '')),
            'hashtags' => trim((string) ($validated['hashtags'] ?? '')),
            'gemini_keys' => $geminiKeys,
            '_logo_path' => $logoAbsolutePath,
        ];

        $timeline = $this->parseTimeline($validated['timeline_json'] ?? null, $duration);
        if ($timeline !== []) {
            $options['timeline'] = $timeline;
        }

        $itemTopics = $generationMode === 'batch'
            ? $batchTopics
            : array_fill(0, $generationMode === 'variants' ? (int) ($validated['variant_count'] ?? 3) : 1, $topic);
        $collection = $generationMode === 'single' ? null : ReelCollection::create([
            'id' => (string) Str::uuid(),
            'type' => $generationMode,
            'label' => $topic,
            'item_count' => count($itemTopics),
        ]);
        $createdReels = [];
        $createdVariants = [];
        $variantTemplates = ['classic', 'clean_news', 'cinematic', 'quote_card', 'bold_stripe', 'numbered_list'];
        $variantMotions = ['zoom_in', 'pan', 'drift', 'tilt_up', 'parallax', 'handheld'];
        $variantSubtitles = ['classic', 'hormozi', 'karaoke', 'minimal', 'news'];
        $variantTransitions = ['auto', 'fade', 'wipe', 'slide', 'varied'];
        $templateIndex = (int) array_search($template, $variantTemplates, true);
        $qualityAnalyzer = app(ReelQualityAnalyzer::class);
        $variantTones = $language === 'en'
            ? ['direct and punchy', 'warm and encouraging', 'curiosity-led', 'calm and practical', 'story-driven']
            : ['مباشرة ومكثفة', 'دافئة ومشجعة', 'تعتمد على الفضول', 'هادئة وعملية', 'قصصية'];
        $durationOffsets = [-5, 0, 5, -3, 3];

        foreach ($itemTopics as $index => $itemTopic) {
            $itemDuration = $duration;
            $itemPrompt = $userPrompt ?? '';
            $itemTemplate = $template;
            $itemCameraMotion = $cameraMotion;
            $itemOptions = $options;

            if ($generationMode === 'variants') {
                $itemDuration = min(60.0, max(3.0, $duration + $durationOffsets[$index]));
                $itemTemplate = $index === 0
                    ? $template
                    : $variantTemplates[($templateIndex + $index) % count($variantTemplates)];
                $itemCameraMotion = $index === 0
                    ? $cameraMotion
                    : $variantMotions[$index % count($variantMotions)];
                if ($index > 0) {
                    $itemOptions['motion_preset'] = 'custom';
                    $itemOptions['subtitle_preset'] = $variantSubtitles[$index % count($variantSubtitles)];
                    $itemOptions['scene_transition'] = $variantTransitions[$index % count($variantTransitions)];
                }
                $tone = $variantTones[$index];
                $directive = $language === 'en'
                    ? sprintf('Create creative alternative %d of %d. Make its opening hook, emotional tone (%s), pacing, and call to action clearly distinct from the other alternatives. Target approximately %d seconds. Keep the requested language and factual caution.', $index + 1, count($itemTopics), $tone, (int) round($itemDuration))
                    : sprintf('أنشئ النسخة الإبداعية %d من %d. اجعل الهوك الافتتاحي والنبرة (%s) والإيقاع وطلب التفاعل مختلفين بوضوح عن بقية النسخ. استهدف مدة تقارب %d ثانية، والتزم بلغة الفيديو والدقة.', $index + 1, count($itemTopics), $tone, (int) round($itemDuration));
                $itemPrompt = trim($itemPrompt."\n\n".$directive);
            } elseif ($generationMode === 'batch') {
                $directive = $language === 'en'
                    ? 'Create one complete short-video script specifically about this batch topic. Use the shared brief as general guidance, not as a replacement for the topic.'
                    : 'أنشئ نص فيديو قصير كامل خاصاً بهذا الموضوع من الدفعة. استخدم الوصف العام كإرشاد مشترك لا بديلاً عن الموضوع.';
                $itemPrompt = trim($itemPrompt."\n\n".$directive);
            }

            $itemTitle = $title;
            if ($generationMode === 'variants') {
                $itemTitle = Str::limit($title, 220, '').($language === 'en' ? ' — Version ' : ' — نسخة ').($index + 1);
            } elseif ($generationMode === 'batch') {
                $itemTitle = ($language === 'en' ? 'Batch: ' : 'دفعة: ').Str::limit($itemTopic, 245, '');
            }

            $qualityReport = $qualityAnalyzer->analyze([
                'language' => $language,
                'headline' => $customHeadline,
                'user_prompt' => $itemPrompt,
                'topic' => $itemTopic,
                'manual_content' => $manualContent,
                'custom_points' => $customPoints,
                'duration' => $itemDuration,
                'aspect_ratio' => $aspectRatio,
            ]);

            $reel = Reel::create([
                'title' => $itemTitle,
                'handle' => $handle,
                'topic' => $itemTopic,
                'user_prompt' => $itemPrompt !== '' ? $itemPrompt : null,
                'niche' => $niche,
                'target_country' => $targetCountry,
                'goal' => $goal,
                'language' => $language,
                'style' => $style,
                'template' => $itemTemplate,
                'format_type' => $formatType,
                'aspect_ratio' => $aspectRatio,
                'camera_motion' => $itemCameraMotion,
                'anti_border_warning' => $antiBorder,
                'duration' => $itemDuration,
                'bg_music_path' => $storedMusicPath,
                'input_videos' => $storedInputPaths,
                'status' => 'queued',
                'progress' => 0,
                'progress_stage' => 'queued',
                'collection_id' => $collection?->id,
                'collection_position' => $collection ? $index + 1 : null,
                'quality_report' => $qualityReport,
            ]);
            $reel->forceFill([
                'render_options' => array_diff_key($itemOptions, ['gemini_keys' => true]),
            ])->save();
            $createdReels[] = $reel;
            $createdVariants[] = ['reel' => $reel, 'options' => $itemOptions];
        }

        Storage::disk('public')->makeDirectory('reels');
        $queuedCount = 0;
        foreach ($createdVariants as ['reel' => $reel, 'options' => $itemOptions]) {
            $outputRelativePath = 'reels/reel_'.$reel->id.'_'.time().'_'.Str::lower(Str::random(6)).'.mp4';
            $dispatchToken = (string) Str::uuid();
            $reelOptions = array_merge($itemOptions, ['_dispatch_token' => $dispatchToken]);
            $reel->forceFill([
                'render_options' => array_diff_key($reelOptions, ['gemini_keys' => true]),
            ])->save();
            try {
                GenerateReelVideo::dispatch($reel, $uploadedVideoPaths, $musicPath, $reelOptions, $outputRelativePath, $dispatchToken);
                $queuedCount++;
            } catch (\Throwable $exception) {
                logger()->error('Could not queue the reel render.', ['reel_id' => $reel->id, 'exception' => $exception->getMessage()]);
                $reel->forceFill([
                    'status' => 'failed',
                    'progress_stage' => 'queue_failed',
                    'error_message' => trans('studio.errors.queue_failed', [], $uiLanguage),
                ])->save();
            }
        }

        if ($queuedCount === 0) {
            return redirect()->route('reels.index', ['lang' => $uiLanguage])
                ->with('error', trans('studio.errors.queue_failed', [], $uiLanguage));
        }

        $message = $generationMode === 'single'
            ? trans('studio.flash.queued', [], $uiLanguage)
            : trans('studio.flash.collection_queued', ['count' => $queuedCount], $uiLanguage);

        return redirect()->route('reels.index', ['active' => $createdReels[0]->id, 'lang' => $uiLanguage])
            ->with('success', $message);
    }

    /**
     * One-click scenario: turn a plain idea into a complete script via Gemini,
     * then queue the render immediately with smart studio defaults.
     */
    public function scenario(Request $request): RedirectResponse
    {
        $backgrounds = $this->backgroundCatalog();
        $backgroundIds = array_column($backgrounds, 'id');

        $validated = $request->validate([
            'idea' => ['required', 'string', 'max:1000'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'template' => ['nullable', Rule::in(['classic', 'clean_news', 'cinematic', 'quote_card', 'bold_stripe', 'numbered_list'])],
            'format_type' => ['nullable', Rule::in(['full_idea', 'quick_tip', 'list', 'story'])],
            'niche' => ['nullable', 'string', Rule::in(['money', 'habits', 'psychology', 'tech', 'business', 'productivity'])],
            'target_country' => ['nullable', Rule::in(['US', 'UK', 'Canada', 'Australia', 'Global'])],
            'goal' => ['nullable', 'string', Rule::in(['followers', 'saves', 'shares', 'comments'])],
            'handle' => ['nullable', 'string', 'max:100'],
            'accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'font_family' => ['nullable', Rule::in(array_keys(config('studio.engine.arabic_fonts', [])))],
            'text_effect' => ['nullable', Rule::in(['none', 'fade_in', 'slide_up', 'pop_up'])],
            ...$this->voiceRules(),
            'sound_effects_enabled' => ['nullable', 'boolean'],
            'duration' => ['nullable', 'integer', 'min:10', 'max:60'],
            'bg_preset' => ['nullable', Rule::in($backgroundIds)],
            'videos' => ['nullable', 'array', 'max:5'],
            'videos.*' => ['file', 'mimes:mp4,mov,avi,webm', 'max:'.((int) config('studio.limits.max_video_mb', 100) * 1024)],
        ]);

        $uiLanguage = $this->interfaceLanguage();
        $language = $validated['language'] ?? $uiLanguage;
        $ideaText = trim($validated['idea']);
        $duration = (float) ($validated['duration'] ?? 60.0);
        $pacing = new VideoScriptPacing;
        $sceneCount = $pacing->sceneCount($duration);
        $targetWords = $pacing->targetWordCount($duration);
        $voiceoverEnabled = $request->boolean('voiceover_enabled');
        $selectedVoiceId = trim((string) ($validated['voice_id'] ?? ''));

        if ($voiceoverEnabled && ! $this->voiceoverReady($language, $selectedVoiceId)) {
            $message = $uiLanguage === 'en'
                ? 'Add an active ElevenLabs key and a Voice ID for the selected video language before generating a narrated scenario.'
                : 'أضف مفتاح ElevenLabs نشطاً وVoice ID للغة الفيديو المحددة قبل توليد سيناريو مع تعليق صوتي.';

            return back()->withErrors(['voiceover_enabled' => $message])->withInput();
        }

        $configuredGeminiKeys = json_decode((string) config('services.gemini.keys_json', '[]'), true);
        $configuredGeminiKeys = is_array($configuredGeminiKeys) ? $configuredGeminiKeys : [];
        $geminiKeys = array_values(array_unique(array_filter(
            array_merge($this->activeProviderKeys('gemini'), $configuredGeminiKeys),
            static fn ($key): bool => is_string($key) && trim($key) !== ''
        )));

        if ($geminiKeys === []) {
            $message = $uiLanguage === 'en'
                ? 'Add an active Gemini API key before generating a scenario. Local generation is disabled.'
                : 'أضف مفتاح Gemini API مفعّلاً قبل توليد السيناريو. التوليد المحلي معطّل.';

            return back()->withErrors(['idea' => $message])->withInput();
        }

        try {
            $result = app(GeminiContentService::class)->generate($this->geminiPrompt(
                'Return JSON with keys title,headline,points,caption,hashtags. Turn the plain idea into one complete short-video script: title is a short storage label, headline is one spoken opening hook of at most 12 words, points is exactly '.$sceneCount.' spoken scenes that build on the hook, and the complete spoken script should be about '.$targetWords.' words to fit the requested duration at a natural pace. Give every scene one concrete detail and keep its on-screen text concise. Caption is a ready-to-post social caption, and hashtags is one space-separated hashtag string. Keep every claim verifiable and write everything in the requested language.',
                [
                    'idea' => $ideaText,
                    'language' => $language,
                    'niche' => $validated['niche'] ?? 'productivity',
                    'goal' => $validated['goal'] ?? 'followers',
                    'target_country' => $validated['target_country'] ?? 'Global',
                    'duration_seconds' => (int) round($duration),
                    'scene_count' => $sceneCount,
                    'target_spoken_words' => $targetWords,
                ]
            ));
            $script = $result['data'];
        } catch (\Throwable $exception) {
            return back()->withErrors(['idea' => trans('studio.errors.gemini_failed', [], $uiLanguage)])->withInput();
        }

        $headline = trim((string) ($script['headline'] ?? ''));
        $points = array_values(array_filter(array_map(
            static fn ($point): string => trim((string) $point),
            array_slice(is_array($script['points'] ?? null) ? $script['points'] : [], 0, $sceneCount)
        ), static fn (string $point): bool => $point !== ''));
        $caption = trim((string) ($script['caption'] ?? ''));
        $hashtags = trim((string) ($script['hashtags'] ?? ''));
        $title = trim((string) ($script['title'] ?? ''))
            ?: (($language === 'en' ? 'Scenario: ' : 'سيناريو: ').Str::limit($ideaText, 60, ''));

        if ($headline === '' || $points === []) {
            $message = $uiLanguage === 'en'
                ? 'Gemini returned an incomplete scenario. Try rephrasing the idea.'
                : 'أعاد Gemini سيناريو ناقصاً. جرّب إعادة صياغة الفكرة.';

            return back()->withErrors(['idea' => $message])->withInput();
        }

        $localDisk = Storage::disk('local');
        $uploadedVideoPaths = [];
        $storedInputPaths = [];
        $backgroundId = $validated['bg_preset'] ?? ($backgrounds[0]['id'] ?? null);
        $backgroundSequence = false;

        if ($request->hasFile('videos') && count($request->file('videos')) > 0) {
            foreach ($request->file('videos') as $file) {
                $path = $file->store('input_videos', 'local');
                if ($path) {
                    $storedInputPaths[] = $path;
                    $uploadedVideoPaths[] = $localDisk->path($path);
                }
            }
        } else {
            $backgroundPaths = app(BackgroundSequenceSelector::class)->select($backgrounds, $backgroundId, $duration);
            if ($backgroundPaths !== []) {
                $uploadedVideoPaths = $backgroundPaths;
                $backgroundSequence = count($backgroundPaths) > 1;
            } else {
                $sampleSource = base_path('../python_engine/storage/input/sample.mp4');
                if (is_file($sampleSource)) {
                    $uploadedVideoPaths[] = $sampleSource;
                }
            }
        }

        if ($uploadedVideoPaths === []) {
            return back()->withErrors([
                'idea' => $uiLanguage === 'en' ? 'A source video is required.' : 'يجب توفر مقطع فيديو للمعالجة.',
            ])->withInput();
        }

        $options = [
            'font_scale' => 100,
            'font_family' => (string) ($validated['font_family'] ?? 'Tajawal'),
            'text_effect' => (string) ($validated['text_effect'] ?? 'slide_up'),
            'background_dimming' => 35,
            'anti_border' => true,
            ...$this->voiceOptions($validated, $voiceoverEnabled, $selectedVoiceId),
            'sound_effects' => $request->boolean('sound_effects_enabled'),
            'accent_color' => $validated['accent_color'] ?? '#D6B87A',
            'quality' => 1080,
            'captions' => true,
            'auto_music' => false,
            'manual_content' => true,
            'motion_preset' => 'cinematic_depth',
            'text_motion' => 'vertical',
            'scene_transition' => 'auto',
            'color_grade' => 'cinematic',
            'hook_word_by_word' => true,
            'highlight_important' => true,
            'bg_preset' => $backgroundId,
            'background_sequence' => $backgroundSequence,
            'custom_headline' => $headline,
            'custom_points' => $points,
            'caption' => $caption,
            'hashtags' => $hashtags,
            'gemini_keys' => [],
        ];

        $qualityReport = app(ReelQualityAnalyzer::class)->analyze([
            'language' => $language,
            'headline' => $headline,
            'user_prompt' => $ideaText,
            'topic' => Str::limit($ideaText, 50, ''),
            'manual_content' => true,
            'custom_points' => $points,
            'duration' => $duration,
            'aspect_ratio' => '9:16',
        ]);

        $reel = Reel::create([
            'title' => Str::limit($title, 255, ''),
            'handle' => trim((string) ($validated['handle'] ?? '')),
            'topic' => Str::limit($ideaText, 50, ''),
            'user_prompt' => $ideaText,
            'niche' => $validated['niche'] ?? 'productivity',
            'target_country' => $validated['target_country'] ?? 'Global',
            'goal' => $validated['goal'] ?? 'followers',
            'language' => $language,
            'style' => 'engaging',
            'template' => $validated['template'] ?? 'cinematic',
            'format_type' => $validated['format_type'] ?? 'quick_tip',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'static',
            'anti_border_warning' => true,
            'duration' => $duration,
            'input_videos' => $storedInputPaths,
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
            'quality_report' => $qualityReport,
        ]);
        Storage::disk('public')->makeDirectory('reels');
        $outputRelativePath = 'reels/reel_'.$reel->id.'_'.time().'_'.Str::lower(Str::random(6)).'.mp4';
        $dispatchToken = (string) Str::uuid();
        $options['_dispatch_token'] = $dispatchToken;
        $reel->forceFill(['render_options' => $options])->save();

        try {
            GenerateReelVideo::dispatch($reel, $uploadedVideoPaths, null, $options, $outputRelativePath, $dispatchToken);
        } catch (\Throwable $exception) {
            logger()->error('Could not queue the scenario render.', ['reel_id' => $reel->id, 'exception' => $exception->getMessage()]);
            $reel->forceFill([
                'status' => 'failed',
                'progress_stage' => 'queue_failed',
                'error_message' => trans('studio.errors.queue_failed', [], $uiLanguage),
            ])->save();

            return redirect()->route('reels.index', ['lang' => $uiLanguage])
                ->with('error', trans('studio.errors.queue_failed', [], $uiLanguage));
        }

        return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $uiLanguage])
            ->with('success', trans('studio.flash.scenario_queued', [], $uiLanguage))
            ->with('scenario', [
                'headline' => $headline,
                'points' => $points,
                'caption' => $caption,
                'hashtags' => $hashtags,
                'ai_model' => $result['model'],
            ]);
    }

    /**
     * Clone a reference screenshot: Gemini reads the frame, writes a new script
     * with the same idea, and maps its visual style onto the render options.
     */
    public function cloneFromReference(Request $request): RedirectResponse
    {
        $backgrounds = $this->backgroundCatalog();
        $backgroundIds = array_column($backgrounds, 'id');

        $validated = $request->validate([
            'reference_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'idea' => ['nullable', 'string', 'max:1000'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'duration' => ['nullable', 'integer', 'min:10', 'max:60'],
            'handle' => ['nullable', 'string', 'max:100'],
            'bg_preset' => ['nullable', Rule::in($backgroundIds)],
            'use_reference_background' => ['nullable', 'boolean'],
            ...$this->voiceRules(),
            'sound_effects_enabled' => ['nullable', 'boolean'],
        ]);

        $uiLanguage = $this->interfaceLanguage();
        $language = $validated['language'] ?? $uiLanguage;
        $duration = (float) ($validated['duration'] ?? 30.0);
        $idea = trim((string) ($validated['idea'] ?? ''));
        $sceneCount = (new VideoScriptPacing)->sceneCount($duration);
        $voiceoverEnabled = $request->boolean('voiceover_enabled');
        $selectedVoiceId = trim((string) ($validated['voice_id'] ?? ''));

        if ($voiceoverEnabled && ! $this->voiceoverReady($language, $selectedVoiceId)) {
            $message = $uiLanguage === 'en'
                ? 'Add an active ElevenLabs key and a Voice ID for the selected video language before cloning with narration.'
                : 'أضف مفتاح ElevenLabs نشطاً وVoice ID للغة الفيديو المحددة قبل النسخ مع تعليق صوتي.';

            return back()->withErrors(['voiceover_enabled' => $message])->withInput();
        }

        $configuredGeminiKeys = json_decode((string) config('services.gemini.keys_json', '[]'), true);
        $configuredGeminiKeys = is_array($configuredGeminiKeys) ? $configuredGeminiKeys : [];
        $geminiKeys = array_values(array_unique(array_filter(
            array_merge($this->activeProviderKeys('gemini'), $configuredGeminiKeys),
            static fn ($key): bool => is_string($key) && trim($key) !== ''
        )));

        if ($geminiKeys === []) {
            $message = $uiLanguage === 'en'
                ? 'Add an active Gemini API key before analysing a reference screenshot.'
                : 'أضف مفتاح Gemini API مفعّلاً قبل تحليل لقطة المرجع.';

            return back()->withErrors(['reference_image' => $message])->withInput();
        }

        $localDisk = Storage::disk('local');
        $storedImagePath = $request->file('reference_image')->store('input_videos', 'local');
        if (! $storedImagePath) {
            return back()->withErrors([
                'reference_image' => $uiLanguage === 'en' ? 'The screenshot could not be stored.' : 'تعذّر حفظ لقطة المرجع.',
            ])->withInput();
        }

        try {
            $analysis = app(ScreenshotStyleAnalyzer::class)->analyze(
                $localDisk->path($storedImagePath),
                $language,
                $sceneCount,
                $backgroundIds,
                $idea,
            );
        } catch (\Throwable $exception) {
            logger()->warning('Reference screenshot analysis failed.', ['exception' => $exception->getMessage()]);
            $localDisk->delete($storedImagePath);

            return back()->withErrors([
                'reference_image' => $uiLanguage === 'en'
                    ? 'Could not read the reference screenshot. Try a clearer, full-frame shot.'
                    : 'تعذّر قراءة لقطة المرجع. جرّب لقطة أوضح وبالإطار الكامل.',
            ])->withInput();
        }

        $backgroundId = $validated['bg_preset'] ?? ($analysis['background_id'] ?: ($backgrounds[0]['id'] ?? null));
        $backgroundSequence = false;
        $storedInputPaths = [];
        $uploadedVideoPaths = [];

        if ($request->boolean('use_reference_background', true)) {
            $uploadedVideoPaths[] = $localDisk->path($storedImagePath);
            $storedInputPaths[] = $storedImagePath;
        } else {
            $backgroundPaths = app(BackgroundSequenceSelector::class)->select($backgrounds, $backgroundId, $duration);
            if ($backgroundPaths !== []) {
                $uploadedVideoPaths = $backgroundPaths;
                $backgroundSequence = count($backgroundPaths) > 1;
            }
            $localDisk->delete($storedImagePath);
        }

        if ($uploadedVideoPaths === []) {
            return back()->withErrors([
                'reference_image' => $uiLanguage === 'en' ? 'A source video is required.' : 'يجب توفر مصدر فيديو للمعالجة.',
            ])->withInput();
        }

        $title = $analysis['title'] ?: (($language === 'en' ? 'Reference: ' : 'مرجع: ').Str::limit($analysis['topic'], 60, ''));
        $options = [
            'font_scale' => $analysis['font_scale'],
            'font_family' => 'Tajawal',
            'text_effect' => $analysis['text_effect'],
            'background_dimming' => $analysis['background_dimming'],
            'anti_border' => true,
            ...$this->voiceOptions($validated, $voiceoverEnabled, $selectedVoiceId),
            'sound_effects' => $request->boolean('sound_effects_enabled'),
            'sfx_style' => $analysis['sfx_style'],
            'accent_color' => $analysis['accent_color'],
            'quality' => 1080,
            'captions' => true,
            'auto_music' => true,
            'music_mood' => $analysis['music_mood'],
            'manual_content' => true,
            'motion_preset' => $analysis['motion_preset'],
            'text_motion' => 'vertical',
            'scene_transition' => $analysis['scene_transition'],
            'subtitle_preset' => $analysis['subtitle_preset'],
            'color_grade' => $analysis['color_grade'],
            'emoji_auto' => $analysis['emoji_auto'],
            'overlay_grain' => $analysis['overlay_grain'],
            'overlay_light_leak' => $analysis['overlay_light_leak'],
            'overlay_dust' => $analysis['overlay_dust'],
            'hook_word_by_word' => $analysis['hook_word_by_word'],
            'highlight_important' => $analysis['highlight_important'],
            'bg_preset' => $backgroundId,
            'background_sequence' => $backgroundSequence,
            'custom_headline' => $analysis['headline'],
            'custom_points' => $analysis['points'],
            'cta' => $analysis['cta'],
            'caption' => $analysis['caption'],
            'hashtags' => $analysis['hashtags'],
            'gemini_keys' => [],
        ];

        $qualityReport = app(ReelQualityAnalyzer::class)->analyze([
            'language' => $language,
            'headline' => $analysis['headline'],
            'user_prompt' => $idea,
            'topic' => Str::limit($analysis['topic'], 50, ''),
            'manual_content' => true,
            'custom_points' => $analysis['points'],
            'duration' => $duration,
            'aspect_ratio' => '9:16',
        ]);

        $reel = Reel::create([
            'title' => Str::limit($title, 255, ''),
            'handle' => trim((string) ($validated['handle'] ?? '')),
            'topic' => Str::limit($analysis['topic'], 50, ''),
            'user_prompt' => $idea !== '' ? $idea : $analysis['design_notes'],
            'niche' => 'productivity',
            'target_country' => 'Global',
            'goal' => 'followers',
            'language' => $language,
            'style' => 'engaging',
            'template' => $analysis['template'],
            'format_type' => 'quick_tip',
            'aspect_ratio' => '9:16',
            'camera_motion' => $analysis['camera_motion'],
            'anti_border_warning' => true,
            'duration' => $duration,
            'input_videos' => $storedInputPaths,
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
            'quality_report' => $qualityReport,
        ]);
        Storage::disk('public')->makeDirectory('reels');
        $outputRelativePath = 'reels/reel_'.$reel->id.'_'.time().'_'.Str::lower(Str::random(6)).'.mp4';
        $dispatchToken = (string) Str::uuid();
        $options['_dispatch_token'] = $dispatchToken;
        $reel->forceFill(['render_options' => $options])->save();

        try {
            GenerateReelVideo::dispatch($reel, $uploadedVideoPaths, null, $options, $outputRelativePath, $dispatchToken);
        } catch (\Throwable $exception) {
            logger()->error('Could not queue the reference clone render.', ['reel_id' => $reel->id, 'exception' => $exception->getMessage()]);
            $reel->forceFill([
                'status' => 'failed',
                'progress_stage' => 'queue_failed',
                'error_message' => trans('studio.errors.queue_failed', [], $uiLanguage),
            ])->save();

            return redirect()->route('reels.index', ['lang' => $uiLanguage])
                ->with('error', trans('studio.errors.queue_failed', [], $uiLanguage));
        }

        return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $uiLanguage])
            ->with('success', $uiLanguage === 'en'
                ? 'Reference analysed. A matching reel is rendering now.'
                : 'تم تحليل اللقطة. يجري الآن رندر فيديو مشابه للتصميم.')
            ->with('scenario', [
                'headline' => $analysis['headline'],
                'points' => $analysis['points'],
                'caption' => $analysis['caption'],
                'hashtags' => $analysis['hashtags'],
                'ai_model' => $analysis['ai_model'],
                'design_notes' => $analysis['design_notes'],
                'template' => $analysis['template'],
                'subtitle_preset' => $analysis['subtitle_preset'],
            ]);
    }

    public function status(Reel $reel): JsonResponse
    {
        $now = now();
        $isRunning = in_array($reel->status, ['processing', 'cancellation_requested'], true);
        $heartbeatSeconds = max(60, (int) config('studio.limits.heartbeat_timeout_seconds', 600));
        $updatedAt = $reel->updated_at;
        $stalled = $isRunning
            && $updatedAt !== null
            && $updatedAt->lt($now->copy()->subSeconds($heartbeatSeconds));

        return response()->json([
            'status' => $reel->status,
            'progress' => (int) ($reel->progress ?? 0),
            'stage' => $reel->progress_stage,
            'error' => $reel->error_message,
            'ready' => $reel->status === 'completed',
            'cancelled' => $reel->status === 'cancelled',
            'updated_at' => $updatedAt?->toIso8601String(),
            'updated_seconds_ago' => $updatedAt !== null ? (int) round($updatedAt->diffInSeconds($now)) : null,
            'stalled' => $stalled,
            'server_time' => $now->toIso8601String(),
        ]);
    }

    public function cancel(Reel $reel): RedirectResponse
    {
        $language = $this->interfaceLanguage();

        if (! in_array($reel->status, ['queued', 'processing', 'cancellation_requested'], true)) {
            return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $language])
                ->with('error', $language === 'en' ? 'This render is no longer running.' : 'هذا الرندر لم يعد قيد التنفيذ.');
        }

        $reel->forceFill([
            'status' => 'cancellation_requested',
            'progress_stage' => 'cancelling',
            'cancel_requested_at' => now(),
        ])->save();

        return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $language])
            ->with('success', $language === 'en' ? 'Cancellation requested. The worker will stop safely.' : 'تم طلب الإلغاء. سيوقف العامل الرندر بأمان.');
    }

    public function downloadCollection(string $collectionId)
    {
        $language = in_array(session('app_locale'), ['ar', 'en'], true) ? session('app_locale') : 'ar';
        $collection = ReelCollection::with('reels')->findOrFail($collectionId);
        $completed = $collection->reels->filter(static function (Reel $reel): bool {
            return $reel->status === 'completed'
                && is_string($reel->output_video_path)
                && Storage::disk('public')->exists($reel->output_video_path);
        });

        if ($completed->isEmpty()) {
            return redirect()->route('reels.index', ['lang' => $language])
                ->with('error', $language === 'en' ? 'No completed videos are available in this collection yet.' : 'لا توجد فيديوهات مكتملة في هذه المجموعة حتى الآن.');
        }

        if (! class_exists(\ZipArchive::class)) {
            return redirect()->route('reels.index', ['active' => $completed->first()->id, 'lang' => $language])
                ->with('error', $language === 'en' ? 'ZIP export is unavailable because the PHP ZIP extension is missing.' : 'تصدير ZIP غير متاح لأن إضافة ZIP في PHP غير مثبتة.');
        }

        $temporaryDirectory = StudioPaths::tmp();
        if (! is_dir($temporaryDirectory) && ! @mkdir($temporaryDirectory, 0755, true) && ! is_dir($temporaryDirectory)) {
            abort(500, 'Could not prepare a temporary ZIP location.');
        }
        $zipPath = $temporaryDirectory.'/collection_'.Str::uuid().'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            abort(500, 'Could not create the ZIP archive.');
        }

        $added = 0;
        foreach ($completed as $reel) {
            $prefix = sprintf('%02d_%s', $reel->collection_position ?? 0, Str::slug($reel->topic) ?: 'reel_'.$reel->id);
            if ($zip->addFile(Storage::disk('public')->path($reel->output_video_path), $prefix.'.mp4')) {
                $added++;
            }
            if ($reel->subtitle_path && Storage::disk('public')->exists($reel->subtitle_path)) {
                $zip->addFile(Storage::disk('public')->path($reel->subtitle_path), $prefix.'.srt');
            }
            if (is_array($reel->quality_report)) {
                $zip->addFromString('quality/'.$prefix.'.json', json_encode($reel->quality_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
            }
        }
        $zip->close();

        if ($added === 0) {
            @unlink($zipPath);

            return redirect()->route('reels.index', ['lang' => $language])
                ->with('error', $language === 'en' ? 'No exportable MP4 files were found.' : 'لم يتم العثور على ملفات MP4 صالحة للتنزيل.');
        }

        $slug = Str::slug($collection->label ?: ($collection->type === 'batch' ? 'video-batch' : 'video-versions')) ?: 'video-collection';
        $filename = $slug.'-'.($collection->type === 'batch' ? 'batch' : 'versions').'.zip';

        return response()->download($zipPath, $filename, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    public function retry(Reel $reel): RedirectResponse
    {
        $language = $this->interfaceLanguage();

        if ($reel->status === 'completed') {
            return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $language]);
        }

        if (in_array($reel->status, ['queued', 'processing'], true)) {
            return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $language])
                ->with('error', $language === 'en' ? 'This reel is already queued or processing.' : 'هذا الفيديو موجود بالفعل في قائمة الانتظار أو قيد المعالجة.');
        }

        $disk = Storage::disk('local');
        $inputPaths = array_values(array_filter(array_map(
            fn ($path): ?string => is_string($path) && $disk->exists($path) ? $disk->path($path) : null,
            $reel->input_videos ?? [],
        )));

        if ($inputPaths === []) {
            $presetId = $reel->render_options['bg_preset'] ?? null;
            $savedBackground = collect($this->backgroundCatalog())->firstWhere('id', $presetId);
            if ($savedBackground && is_file($savedBackground['path'])) {
                $inputPaths[] = $savedBackground['path'];
            }
        }

        if ($inputPaths === []) {
            $sampleSource = base_path('../python_engine/storage/input/sample.mp4');
            if (is_file($sampleSource)) {
                $inputPaths[] = $sampleSource;
            }
        }

        if ($inputPaths === []) {
            return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $language])
                ->with('error', $language === 'en' ? 'The source video is no longer available.' : 'مقطع المصدر لم يعد متاحاً.');
        }

        $options = array_merge([
            'font_scale' => 100,
            'font_family' => 'Noto Kufi Arabic',
            'text_effect' => 'none',
            'background_dimming' => 35,
            'anti_border' => (bool) $reel->anti_border_warning,
            'voiceover' => false,
            'voiceover_tone' => 'neutral',
            'accent_color' => '#fb7185',
            'quality' => 1080,
            'captions' => true,
            'auto_music' => true,
            'manual_content' => false,
            'custom_headline' => null,
            'custom_points' => [],
            'caption' => '',
            'hashtags' => '',
        ], $reel->render_options ?? []);
        if (in_array($reel->progress_stage, ['voiceover_failed', 'voiceover_unavailable', 'voiceover_required'], true)) {
            $options['voiceover'] = false;
            $reel->render_options = array_merge($reel->render_options ?? [], ['voiceover' => false]);
        }
        $manualContent = (bool) ($options['manual_content'] ?? false);
        $configuredKeys = json_decode((string) config('services.gemini.keys_json', '[]'), true);
        $geminiKeys = $manualContent ? [] : array_values(array_unique(array_filter(array_merge(
            $this->activeProviderKeys('gemini'),
            is_array($configuredKeys) ? $configuredKeys : [],
            [(string) config('services.gemini.api_key', ''), (string) config('services.gemini.google_api_key', '')],
        ), static fn ($key): bool => is_string($key) && trim($key) !== '')));

        if (! $manualContent && $geminiKeys === []) {
            return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $language])
                ->with('error', $language === 'en' ? 'Add an active Gemini API key before retrying.' : 'أضف مفتاح Gemini مفعّلاً قبل إعادة المحاولة.');
        }

        $musicPath = null;
        if ($reel->bg_music_path) {
            // Uploaded tracks live on the local disk; library tracks are shared
            // assets on the public disk.
            foreach (['local', 'public'] as $musicDisk) {
                $candidate = Storage::disk($musicDisk);
                if ($candidate->exists($reel->bg_music_path)) {
                    $musicPath = $candidate->path($reel->bg_music_path);
                    break;
                }
            }
        }
        $outputRelativePath = 'reels/reel_'.$reel->id.'_'.time().'_'.Str::lower(Str::random(8)).'.mp4';
        if (filled($reel->output_video_path)) {
            Storage::disk('public')->delete($reel->output_video_path);
        }

        $dispatchToken = (string) Str::uuid();
        $options['_dispatch_token'] = $dispatchToken;

        $reel->forceFill([
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
            'error_message' => null,
            'output_video_path' => null,
            'output_video_url' => null,
            'thumbnail_path' => null,
            'subtitle_path' => null,
            'cancel_requested_at' => null,
            'render_options' => array_merge($reel->render_options ?? [], ['_dispatch_token' => $dispatchToken]),
        ])->save();

        $options['gemini_keys'] = $geminiKeys;
        GenerateReelVideo::dispatch($reel->fresh(), $inputPaths, $musicPath, $options, $outputRelativePath, $dispatchToken);

        return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $language])
            ->with('success', $language === 'en' ? 'The reel was queued for another attempt.' : 'تمت إعادة الفيديو إلى قائمة الانتظار.');
    }

    public function thumbnail(Reel $reel): Response
    {
        return $this->servePublicMedia($reel->thumbnail_path, ['thumbnails']);
    }

    public function subtitle(Reel $reel): Response
    {
        $path = $reel->subtitle_path;

        if (! $this->isOwnedStoragePath($path, ['subtitles'])) {
            abort(404);
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->download($path, 'reel_'.$reel->getKey().'.srt');
    }

    public function previewMedia(string $previewId): Response
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/D', $previewId) !== 1) {
            abort(404);
        }

        return $this->servePublicMedia('previews/'.$previewId.'.png', ['previews']);
    }

    private function servePublicMedia(mixed $path, array $directories): Response
    {
        if (! $this->isOwnedStoragePath($path, $directories)) {
            abort(404);
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            abort(404);
        }

        return response()->file($disk->path($path), [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function stream(Request $request, Reel $reel): Response
    {
        $disk = Storage::disk('public');
        $relativePath = $reel->output_video_path;

        if (! $relativePath || ! $disk->exists($relativePath)) {
            abort(404);
        }

        $path = $disk->path($relativePath);
        $size = filesize($path);
        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;
        $range = $request->header('Range');

        if ($range !== null) {
            if (! preg_match('/^bytes=(\d*)-(\d*)$/D', trim($range), $matches)
                || ($matches[1] === '' && $matches[2] === '')
                || $size === 0) {
                return response('', 416, [
                    'Content-Range' => sprintf('bytes */%d', $size),
                    'Accept-Ranges' => 'bytes',
                ]);
            }

            if ($matches[1] === '') {
                $suffixLength = (int) $matches[2];
                if ($suffixLength === 0) {
                    return response('', 416, [
                        'Content-Range' => sprintf('bytes */%d', $size),
                        'Accept-Ranges' => 'bytes',
                    ]);
                }

                $start = max(0, $size - $suffixLength);
                $end = $size - 1;
            } else {
                $start = (int) $matches[1];
                $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
            }

            if ($start >= $size || $start > $end) {
                return response('', 416, [
                    'Content-Range' => sprintf('bytes */%d', $size),
                    'Accept-Ranges' => 'bytes',
                ]);
            }

            $status = 206;
        }

        $length = $end - $start + 1;
        $headers = [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache, private',
            'Content-Length' => (string) $length,
        ];

        if ($status === 206) {
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }

        return response()->stream(function () use ($path, $start, $length): void {
            $file = fopen($path, 'rb');
            if ($file === false) {
                return;
            }

            if ($start > 0) {
                fseek($file, $start);
            }

            $remaining = $length;
            while ($remaining > 0 && ! feof($file)) {
                $chunk = fread($file, min(8192, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                $remaining -= strlen($chunk);
            }

            fclose($file);
        }, $status, $headers);

    }

    public function destroy(Reel $reel)
    {
        $collectionId = $reel->collection_id;

        if ($this->isOwnedStoragePath($reel->output_video_path, ['reels'])
            && Storage::disk('public')->exists($reel->output_video_path)) {
            Storage::disk('public')->delete($reel->output_video_path);
        }

        foreach ([$reel->thumbnail_path, $reel->subtitle_path] as $mediaPath) {
            if ($this->isOwnedStoragePath($mediaPath, ['thumbnails', 'subtitles'])
                && Storage::disk('public')->exists($mediaPath)) {
                Storage::disk('public')->delete($mediaPath);
            }
        }

        $localFiles = array_merge($reel->input_videos ?? [], array_filter([$reel->bg_music_path]));
        foreach ($localFiles as $path) {
            if ($this->isOwnedStoragePath($path, ['input_videos', 'bg_music'])) {
                $this->deleteLocalMediaIfUnreferenced($reel, $path);
            }
        }

        $reel->delete();
        if ($collectionId) {
            ReelCollection::query()->whereKey($collectionId)->doesntHave('reels')->delete();
        }

        $lang = session('app_locale', 'ar');

        $message = $lang === 'en' ? 'Reel and its stored media were deleted.' : 'تم حذف الفيديو وملفاته المحفوظة.';

        return redirect()->route('reels.index', ['lang' => $lang])->with('success', $message);
    }

    public function generateScript(Request $request, ReelContentGenerator $contentGenerator): JsonResponse
    {
        $input = $request->validate([
            'user_prompt' => ['nullable', 'string', 'max:1000'],
            'topic' => ['nullable', 'string', 'max:255'],
            'selected_idea' => ['nullable', 'string', 'max:255'],
            'selected_idea_description' => ['nullable', 'string', 'max:1000'],
            'selected_idea_angle' => ['nullable', 'string', 'max:500'],
            'headline' => ['required', 'string', 'max:255'],
            'language' => ['required', Rule::in(['ar', 'en'])],
            'duration' => ['required', 'integer', 'min:10', 'max:60'],
            'niche' => ['nullable', 'string', Rule::in(['money', 'habits', 'psychology', 'tech', 'business', 'productivity'])],
            'target_country' => ['nullable', Rule::in(['US', 'UK', 'Canada', 'Australia', 'Global'])],
            'goal' => ['nullable', Rule::in(['followers', 'saves', 'shares', 'comments'])],
            'style' => ['nullable', 'string', 'max:100'],
            'format_type' => ['nullable', Rule::in(['full_idea', 'quick_tip', 'list', 'story'])],
        ]);
        $brief = trim((string) ($input['user_prompt'] ?? ''));
        $selectedIdea = trim((string) ($input['selected_idea'] ?? ''));
        $topic = $selectedIdea ?: trim((string) ($input['topic'] ?? ''));
        if ($topic === '' && $brief === '') {
            $message = $this->interfaceLanguage() === 'en'
                ? 'Enter a video brief or choose an idea before generating the script.'
                : 'اكتب وصف الفيديو أو اعتمد فكرة قبل توليد النص.';

            return response()->json(['status' => 'error', 'message' => $message], 422);
        }

        $topic = $topic !== '' ? $topic : Str::limit($brief, 255, '');
        $context = array_filter([
            $brief !== '' ? 'Video brief: '.$brief : null,
            $selectedIdea !== '' ? 'Selected idea: '.$selectedIdea : null,
            trim((string) ($input['selected_idea_description'] ?? '')) !== '' ? 'Idea description: '.trim((string) $input['selected_idea_description']) : null,
            trim((string) ($input['selected_idea_angle'] ?? '')) !== '' ? 'Idea angle: '.trim((string) $input['selected_idea_angle']) : null,
        ]);
        $reel = new Reel([
            'topic' => $topic,
            'user_prompt' => implode("\n", $context),
            'niche' => $input['niche'] ?? 'productivity',
            'target_country' => $input['target_country'] ?? 'Global',
            'goal' => $input['goal'] ?? 'followers',
            'language' => $input['language'],
            'style' => $input['style'] ?? 'engaging',
            'format_type' => $input['format_type'] ?? 'full_idea',
            'duration' => (float) $input['duration'],
        ]);

        try {
            $script = $contentGenerator->generate($reel, [
                'custom_headline' => trim($input['headline']),
            ]);
            $expectedScenes = (new VideoScriptPacing)->sceneCount((float) $input['duration']);
            if (count($script['points']) !== $expectedScenes) {
                $message = $this->interfaceLanguage() === 'en'
                    ? 'Gemini returned an incomplete script. Please generate it again.'
                    : 'أعاد Gemini نصاً غير مكتمل. أعد توليده من فضلك.';

                return response()->json(['status' => 'error', 'message' => $message], 502);
            }

            return response()->json([
                'status' => 'success',
                'script' => [
                    'headline' => $script['headline'],
                    'points' => $script['points'],
                    'cta' => $script['cta'],
                    'caption' => $script['caption'],
                    'hashtags' => $script['hashtags'],
                    'content_source' => $script['content_source'],
                ],
            ]);
        } catch (\Throwable $exception) {
            return $this->geminiFailure($exception);
        }
    }

    public function suggestIdea(Request $request): JsonResponse
    {
        $input = $request->validate([
            'prompt' => ['nullable', 'string', 'max:1000'],
            'topic' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
        ]);
        try {
            $result = app(GeminiContentService::class)->generate($this->geminiPrompt(
                'Return JSON with keys badge,title,desc,why,angle,hook,hook_desc. Create one accurate short-video idea.',
                ['topic' => trim($input['topic'] ?? '') ?: trim($input['prompt'] ?? ''), 'language' => $input['language'] ?? 'en']
            ));

            return response()->json(['status' => 'success', 'idea' => $result['data'], 'ai_model' => $result['model']]);
        } catch (\Throwable $exception) {
            return $this->geminiFailure($exception);
        }

    }

    public function suggestHook(Request $request): JsonResponse
    {
        $input = $request->validate([
            'topic' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
        ]);
        try {
            $result = app(GeminiContentService::class)->generate($this->geminiPrompt(
                'Return JSON with keys badge,headline,desc. Create one strong but accurate hook for a short video.',
                ['topic' => trim($input['topic'] ?? ''), 'language' => $input['language'] ?? 'en']
            ));

            return response()->json(['status' => 'success', 'hook' => $result['data'], 'ai_model' => $result['model']]);
        } catch (\Throwable $exception) {
            return $this->geminiFailure($exception);
        }

    }

    public function refineScript(Request $request): JsonResponse
    {
        $input = $request->validate([
            'action' => ['nullable', Rule::in(['punctuate', 'shorten', 'simple'])],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'line1' => ['nullable', 'string', 'max:500'],
            'line2' => ['nullable', 'string', 'max:500'],
            'line3' => ['nullable', 'string', 'max:500'],
            'lines' => ['nullable', 'array', 'min:1', 'max:10'],
            'lines.*' => ['required', 'string', 'max:500'],
        ]);
        $lines = $input['lines'] ?? [
            $input['line1'] ?? '',
            $input['line2'] ?? '',
            $input['line3'] ?? '',
        ];
        $lines = array_values(array_map(static fn (string $line): string => trim($line), $lines));
        try {
            $result = app(GeminiContentService::class)->generate($this->geminiPrompt(
                'Return JSON with key lines containing exactly '.count($lines).' concise rewritten script lines. Apply the requested action.',
                ['action' => $input['action'] ?? 'punctuate', 'language' => $input['language'] ?? 'en', 'lines' => $lines]
            ));

            $refinedLines = array_values(array_filter(
                array_map(static fn (mixed $line): string => is_string($line) ? trim($line) : '', (array) ($result['data']['lines'] ?? [])),
                static fn (string $line): bool => $line !== '',
            ));
            if (count($refinedLines) !== count($lines)) {
                return response()->json([
                    'status' => 'error',
                    'message' => $this->interfaceLanguage() === 'en'
                        ? 'Gemini returned an incomplete revision. Try again.'
                        : 'أعاد Gemini تعديلاً غير مكتمل. حاول مجدداً.',
                ], 502);
            }

            return response()->json(['status' => 'success', 'lines' => $refinedLines, 'ai_model' => $result['model']]);
        } catch (\Throwable $exception) {
            return $this->geminiFailure($exception);
        }

    }

    public function expertAdvice(Request $request): JsonResponse
    {
        $input = $request->validate([
            'language' => ['nullable', Rule::in(['ar', 'en'])],
        ]);
        $tips = ($input['language'] ?? 'ar') === 'ar'
            ? [
                ['icon' => 'fa-bolt', 'title' => 'ابدأ بفائدة واضحة', 'desc' => 'وضح ما الذي سيستفيده المشاهد من دون وعد بنتيجة مضمونة.', 'action_step' => 3],
                ['icon' => 'fa-list-check', 'title' => 'قسّم الفكرة إلى نقاط', 'desc' => 'استخدم جملاً قصيرة ومتسلسلة يسهل قراءتها على الشاشة.', 'action_step' => 4],
                ['icon' => 'fa-circle-check', 'title' => 'اختم بخطوة قابلة للتطبيق', 'desc' => 'راجع أن النص يطابق موضوعك وأن أي معلومة قابلة للتحقق قبل النشر.', 'action_step' => 4],
            ]
            : [
                ['icon' => 'fa-bolt', 'title' => 'Lead with a clear benefit', 'desc' => 'Explain what viewers can learn without promising a guaranteed outcome.', 'action_step' => 3],
                ['icon' => 'fa-list-check', 'title' => 'Break the idea into steps', 'desc' => 'Use short, ordered sentences that are easy to read on screen.', 'action_step' => 4],
                ['icon' => 'fa-circle-check', 'title' => 'End with a useful next step', 'desc' => 'Check that the script fits your topic and verify factual claims before publishing.', 'action_step' => 4],
            ];

        return response()->json([
            'status' => 'success',
            'tips' => $tips,
        ]);
    }

    public function generateCaptions(Request $request): JsonResponse
    {
        $input = $request->validate([
            'style' => ['nullable', Rule::in(['primary', 'short', 'curiosity', 'educational', 'story'])],
            'headline' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
        ]);
        try {
            $result = app(GeminiContentService::class)->generate($this->geminiPrompt(
                'Return JSON with keys name,caption,hashtags. Write one social caption in the requested style and language.',
                ['style' => $input['style'] ?? 'primary', 'headline' => $input['headline'] ?? '', 'language' => $input['language'] ?? 'en']
            ));

            return response()->json(['status' => 'success', 'data' => $result['data'], 'ai_model' => $result['model']]);
        } catch (\Throwable $exception) {
            return $this->geminiFailure($exception);
        }
    }

    private function geminiPrompt(string $instruction, array $context): string
    {
        return "You are the content engine for a short-video studio. {$instruction}\n"
            .'Return only valid JSON, no markdown. Avoid unsupported claims and keep text concise. Context: '
            .json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function geminiFailure(\Throwable $exception): JsonResponse
    {
        $message = $this->interfaceLanguage() === 'en'
            ? (str_contains($exception->getMessage(), 'required')
                ? 'Gemini API key is required before generating content.'
                : 'Gemini could not generate this content. Check the active key or try again.')
            : (str_contains($exception->getMessage(), 'required')
                ? 'يلزم إضافة مفتاح Gemini API قبل توليد المحتوى.'
                : 'تعذّر على Gemini توليد هذا المحتوى. تحقق من المفتاح النشط أو حاول مجدداً.');

        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], 502);
    }

    /**
     * Re-render a completed reel in another aspect ratio without retyping anything.
     */
    public function exportAspect(Request $request, Reel $reel): RedirectResponse
    {
        $uiLanguage = $this->interfaceLanguage();
        $language = in_array($reel->language, ['ar', 'en'], true) ? $reel->language : 'ar';
        $validated = $request->validate([
            'aspect_ratio' => ['required', Rule::in(['9:16', '1:1', '16:9', 'all'])],
        ]);
        $requested = $validated['aspect_ratio'];
        $allRatios = ['9:16', '1:1', '16:9'];
        $targets = $requested === 'all'
            ? array_values(array_diff($allRatios, [(string) $reel->aspect_ratio]))
            : [$requested];

        if ($targets === []) {
            return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $uiLanguage])
                ->with('error', $uiLanguage === 'en' ? 'Every aspect ratio is already exported.' : 'كل المقاسات مُصدّرة بالفعل.');
        }

        if (in_array($reel->status, ['queued', 'processing', 'cancellation_requested'], true)) {
            return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $uiLanguage])
                ->with('error', $uiLanguage === 'en' ? 'Wait for the current render to finish first.' : 'انتظر انتهاء الرندر الحالي أولاً.');
        }

        $inputPaths = $this->resolveReelInputs($reel);
        if ($inputPaths === []) {
            return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $uiLanguage])
                ->with('error', $uiLanguage === 'en' ? 'The source video is no longer available.' : 'مقطع المصدر لم يعد متاحاً.');
        }

        $baseOptions = array_merge([
            'font_scale' => 100,
            'font_family' => 'Noto Kufi Arabic',
            'text_effect' => 'none',
            'background_dimming' => 35,
            'anti_border' => (bool) $reel->anti_border_warning,
            'voiceover' => false,
            'accent_color' => '#fb7185',
            'quality' => 1080,
            'captions' => true,
            'auto_music' => true,
            'manual_content' => false,
            'custom_points' => [],
        ], $reel->render_options ?? []);
        unset($baseOptions['_dispatch_token'], $baseOptions['gemini_keys']);

        $storedTimeline = is_array($baseOptions['_last_timeline'] ?? null) ? $baseOptions['_last_timeline'] : [];
        if ($storedTimeline !== []) {
            $headline = '';
            $points = [];
            $cta = '';
            foreach ($storedTimeline as $segment) {
                if (! is_array($segment)) {
                    continue;
                }
                $text = trim((string) ($segment['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                match ($segment['kind'] ?? 'point') {
                    'headline' => $headline = $headline ?: $text,
                    'cta' => $cta = $cta ?: $text,
                    default => $points[] = $text,
                };
            }
            if ($headline !== '' && $points !== []) {
                $baseOptions['timeline'] = $storedTimeline;
                $baseOptions['manual_content'] = true;
                $baseOptions['custom_headline'] = $headline;
                $baseOptions['custom_points'] = $points;
                $baseOptions['cta'] = $cta;
            }
        }

        if (! (bool) ($baseOptions['manual_content'] ?? false)) {
            $configuredKeys = json_decode((string) config('services.gemini.keys_json', '[]'), true);
            $baseOptions['gemini_keys'] = array_values(array_unique(array_filter(array_merge(
                $this->activeProviderKeys('gemini'),
                is_array($configuredKeys) ? $configuredKeys : [],
            ), static fn ($key): bool => is_string($key) && trim($key) !== '')));
        }

        $musicPath = $reel->bg_music_path && Storage::disk('local')->exists($reel->bg_music_path)
            ? Storage::disk('local')->path($reel->bg_music_path)
            : null;

        $created = [];
        foreach ($targets as $aspectRatio) {
            $variant = $this->createAspectVariant($reel, $aspectRatio, $inputPaths, $musicPath, $baseOptions, $uiLanguage);
            if ($variant !== null) {
                $created[] = $variant;
            }
        }

        if ($created === []) {
            return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $uiLanguage])
                ->with('error', trans('studio.errors.queue_failed', [], $uiLanguage));
        }

        $last = $created[array_key_last($created)];

        return redirect()->route('reels.index', ['active' => $last->id, 'lang' => $uiLanguage])
            ->with('success', count($created) > 1
                ? ($uiLanguage === 'en'
                    ? count($created).' aspect-ratio versions were queued for rendering.'
                    : 'تمت إضافة '.count($created).' نسخ بمقاسات مختلفة إلى قائمة الانتظار.')
                : ($uiLanguage === 'en'
                    ? 'A '.$last->aspect_ratio.' version was queued for rendering.'
                    : 'تمت إضافة نسخة بمقاس '.$last->aspect_ratio.' إلى قائمة الانتظار.'));
    }

    /**
     * @param  list<string>  $inputPaths
     * @param  array<string, mixed>  $baseOptions
     */
    private function createAspectVariant(Reel $reel, string $aspectRatio, array $inputPaths, ?string $musicPath, array $baseOptions, string $uiLanguage): ?Reel
    {
        $options = $baseOptions;
        $options['aspect_ratio'] = $aspectRatio;

        $variant = Reel::create([
            'title' => Str::limit((string) $reel->title, 220, '').' — '.$aspectRatio,
            'handle' => $reel->handle,
            'topic' => $reel->topic,
            'user_prompt' => $reel->user_prompt,
            'niche' => $reel->niche,
            'target_country' => $reel->target_country,
            'goal' => $reel->goal,
            'language' => $reel->language,
            'style' => $reel->style,
            'theme' => $reel->theme,
            'template' => $reel->template,
            'format_type' => $reel->format_type,
            'aspect_ratio' => $aspectRatio,
            'anti_border_warning' => $reel->anti_border_warning,
            'camera_motion' => $reel->camera_motion,
            'duration' => $reel->duration,
            'bg_music_path' => $reel->bg_music_path,
            'input_videos' => $reel->input_videos,
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
            'quality_report' => $reel->quality_report,
        ]);

        $dispatchToken = (string) Str::uuid();
        $options['_dispatch_token'] = $dispatchToken;
        $variant->forceFill(['render_options' => array_diff_key($options, ['gemini_keys' => true])])->save();
        $outputRelativePath = 'reels/reel_'.$variant->id.'_'.time().'_'.Str::lower(Str::random(8)).'.mp4';

        try {
            GenerateReelVideo::dispatch($variant, $inputPaths, $musicPath, $options, $outputRelativePath, $dispatchToken);
        } catch (\Throwable $exception) {
            $variant->forceFill([
                'status' => 'failed',
                'progress_stage' => 'queue_failed',
                'error_message' => trans('studio.errors.queue_failed', [], $uiLanguage),
            ])->save();

            return null;
        }

        return $variant;
    }

    /**
     * Regenerate only the hook, the last scene, or the CTA for the wizard.
     */
    public function regeneratePart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'part' => ['required', Rule::in(['hook', 'last_scene', 'cta'])],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'niche' => ['nullable', 'string', 'max:50'],
            'topic' => ['nullable', 'string', 'max:255'],
            'headline' => ['nullable', 'string', 'max:255'],
            'points' => ['nullable', 'array', 'max:12'],
            'points.*' => ['nullable', 'string', 'max:500'],
        ]);

        $language = $validated['language'] ?? 'ar';
        $part = $validated['part'];
        $points = array_values(array_filter(array_map(
            static fn ($point): string => trim((string) $point),
            is_array($validated['points'] ?? null) ? $validated['points'] : [],
        ), static fn (string $point): bool => $point !== ''));
        $headline = trim((string) ($validated['headline'] ?? ''));
        $lastPoint = $points !== [] ? $points[array_key_last($points)] : '';

        $instruction = match ($part) {
            'hook' => 'Return JSON with key headline. Write one fresh opening hook of at most 12 words that is clearly different from the current one and still answers the topic.',
            'last_scene' => 'Return JSON with key point. Write one fresh final scene of at most 20 words that closes the argument with a concrete takeaway and does not repeat earlier scenes.',
            default => 'Return JSON with key cta. Write one fresh short call to action of at most 6 words.',
        };

        try {
            $result = app(GeminiContentService::class)->generate($this->geminiPrompt($instruction, [
                'language' => $language,
                'niche' => $validated['niche'] ?? 'productivity',
                'topic' => $validated['topic'] ?? '',
                'current_headline' => $headline,
                'scenes' => $points,
                'current_last_scene' => $lastPoint,
            ]));
        } catch (\Throwable $exception) {
            return $this->geminiFailure($exception);
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $response = ['status' => 'success', 'part' => $part, 'ai_model' => $result['model'] ?? null];

        if ($part === 'hook') {
            $value = trim((string) ($data['headline'] ?? $data['hook'] ?? ''));
            if ($value === '') {
                return response()->json(['status' => 'error', 'message' => 'Gemini returned an empty hook.'], 502);
            }
            $response['headline'] = Str::limit($value, 255, '');
        } elseif ($part === 'last_scene') {
            $value = trim((string) ($data['point'] ?? $data['scene'] ?? $data['text'] ?? ''));
            if ($value === '') {
                return response()->json(['status' => 'error', 'message' => 'Gemini returned an empty scene.'], 502);
            }
            $response['points'] = $points;
            $response['points'][max(0, count($points) - 1)] = Str::limit($value, 500, '');
            if ($response['points'] === []) {
                $response['points'] = [Str::limit($value, 500, '')];
            }
        } else {
            $value = trim((string) ($data['cta'] ?? $data['call_to_action'] ?? ''));
            if ($value === '') {
                return response()->json(['status' => 'error', 'message' => 'Gemini returned an empty call to action.'], 502);
            }
            $response['cta'] = Str::limit($value, 120, '');
        }

        return response()->json($response);
    }

    /**
     * @return list<string>
     */
    private function resolveReelInputs(Reel $reel): array
    {
        $disk = Storage::disk('local');
        $inputPaths = array_values(array_filter(array_map(
            fn ($path): ?string => is_string($path) && $disk->exists($path) ? $disk->path($path) : null,
            $reel->input_videos ?? [],
        )));

        if ($inputPaths === []) {
            $presetId = $reel->render_options['bg_preset'] ?? null;
            $savedBackground = collect($this->backgroundCatalog())->firstWhere('id', $presetId);
            if ($savedBackground && is_file($savedBackground['path'])) {
                $inputPaths[] = $savedBackground['path'];
            }
        }

        if ($inputPaths === []) {
            $sampleSource = base_path('../python_engine/storage/input/sample.mp4');
            if (is_file($sampleSource)) {
                $inputPaths[] = $sampleSource;
            }
        }

        return $inputPaths;
    }

    /**
     * @return list<array{kind:string,text:string,start:float,end:float}>
     */
    private function parseTimeline(?string $json, float $duration): array
    {
        $json = trim((string) $json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $duration = max(3.0, $duration);
        $segments = [];
        foreach (array_slice($decoded, 0, 40) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $text = trim((string) (preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text)) ?? $text));
            if ($text === '') {
                continue;
            }
            $kind = in_array($item['kind'] ?? '', ['headline', 'point', 'cta'], true) ? (string) $item['kind'] : 'point';
            $start = max(0.0, min($duration - 0.1, (float) ($item['start'] ?? 0)));
            $end = max($start + 0.1, min($duration, (float) ($item['end'] ?? $start + 2.0)));
            $segments[] = [
                'kind' => $kind,
                'text' => Str::limit($text, 500, ''),
                'start' => round($start, 2),
                'end' => round($end, 2),
            ];
        }

        return $segments;
    }

    private function activeProviderKeys(string $provider): array
    {
        return app(ProviderKeyRing::class)->activeValues($provider);
    }

    private function interfaceLanguage(): string
    {
        $language = session('app_locale');

        return in_array($language, ['ar', 'en'], true) ? $language : 'ar';
    }

    private function configuredElevenLabsKeys(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            $this->activeProviderKeys('elevenlabs'),
            [(string) config('services.elevenlabs.api_key', '')],
        ), static fn ($key): bool => is_string($key) && trim($key) !== '')));
    }

    private function elevenLabsVoiceId(string $language): string
    {
        $setting = ProviderSetting::query()->where('name', 'elevenlabs.voice_id.'.$language)->first();

        return trim((string) ($setting?->value ?: config("services.elevenlabs.voice_ids.$language", '')));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function voiceRules(): array
    {
        return [
            'voiceover_enabled' => ['nullable', 'boolean'],
            'voiceover_tone' => ['nullable', Rule::in(['neutral', 'calm', 'warm', 'energetic'])],
            'voice_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'voice_stability' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'voice_similarity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'voice_style' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'voice_speed' => ['nullable', 'numeric', 'min:0.7', 'max:1.2'],
            'voice_boost' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function voiceOptions(array $validated, bool $enabled, string $selectedVoiceId): array
    {
        return [
            'voiceover' => $enabled,
            'voiceover_tone' => $validated['voiceover_tone'] ?? 'warm',
            'voice_id' => $selectedVoiceId !== '' ? $selectedVoiceId : null,
            'voice_settings' => array_filter([
                'stability' => isset($validated['voice_stability']) ? (float) $validated['voice_stability'] : null,
                'similarity' => isset($validated['voice_similarity']) ? (float) $validated['voice_similarity'] : null,
                'style' => isset($validated['voice_style']) ? (float) $validated['voice_style'] : null,
                'speed' => isset($validated['voice_speed']) ? (float) $validated['voice_speed'] : null,
                'speaker_boost' => isset($validated['voice_boost']) ? (bool) $validated['voice_boost'] : null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    private function voiceoverReady(string $language, string $selectedVoiceId): bool
    {
        return $this->configuredElevenLabsKeys() !== []
            && ($selectedVoiceId !== '' || filled($this->elevenLabsVoiceId($language)));
    }

    /**
     * @return array<string, mixed>
     */
    private function voiceProfile(): array
    {
        $settings = auth()->user()?->getAttribute('settings');
        $profile = is_array($settings) ? ($settings['voice_profile'] ?? []) : [];

        return is_array($profile) ? $profile : [];
    }

    /**
     * @return list<array{id:string,name:string,language:?string,labels:array<string,string>,preview_url:?string}>
     */
    private function elevenLabsVoiceCatalog(): array
    {
        $voices = app(ElevenLabsVoiceoverService::class)->voices();
        $known = array_column($voices, 'id');
        foreach (['ar', 'en'] as $language) {
            $configured = $this->elevenLabsVoiceId($language);
            if ($configured === '' || in_array($configured, $known, true)) {
                continue;
            }
            $voices[] = [
                'id' => $configured,
                'name' => $language === 'ar' ? 'الصوت الافتراضي (عربي)' : 'Default voice (English)',
                'language' => $language,
                'labels' => [],
                'preview_url' => null,
            ];
            $known[] = $configured;
        }

        return $voices;
    }

    private function backgroundCatalog(): array
    {
        $directory = StudioPaths::backgrounds();

        return array_values(array_filter(array_map(function (array $background) use ($directory) {
            $isPublicAsset = ($background['asset_directory'] ?? 'storage') === 'public';
            $assetDirectory = $isPublicAsset ? public_path('backgrounds') : $directory;
            $assetUrlDirectory = $isPublicAsset ? 'backgrounds' : 'storage/backgrounds';
            $videoPath = $assetDirectory.'/'.$background['file'];
            $posterPath = $assetDirectory.'/'.$background['poster'];

            if (! is_file($videoPath) || ! is_file($posterPath)) {
                return null;
            }

            return array_merge($background, [
                'path' => $videoPath,
                'media_url' => asset($assetUrlDirectory.'/'.$background['file']),
                'poster_url' => asset($assetUrlDirectory.'/'.$background['poster']),
            ]);
        }, config('reel-backgrounds', []))));
    }

    /**
     * Audio files stored in storage/app/public/music become selectable tracks.
     *
     * @return list<array{path:string,label:string}>
     */
    private function musicTrackCatalog(): array
    {
        $tracks = [];
        foreach (Storage::disk('public')->files('music') as $file) {
            if (! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['mp3', 'wav', 'm4a'], true)) {
                continue;
            }
            $tracks[] = [
                'path' => $file,
                'label' => Str::headline(pathinfo($file, PATHINFO_FILENAME)),
            ];
        }

        usort($tracks, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $tracks;
    }

    private function isOwnedStoragePath(mixed $path, array $directories): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }

        $segments = explode('/', $path);
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return false;
        }

        foreach ($directories as $directory) {
            if (Str::startsWith($path, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    private function deleteLocalMediaIfUnreferenced(Reel $reel, string $path): void
    {
        $isStillReferenced = Reel::query()
            ->where('id', '<>', $reel->getKey())
            ->get(['input_videos', 'bg_music_path'])
            ->contains(static function (Reel $candidate) use ($path): bool {
                return $candidate->bg_music_path === $path
                    || in_array($path, $candidate->input_videos ?? [], true);
            });

        if (! $isStillReferenced) {
            Storage::disk('local')->delete($path);
        }
    }
}
