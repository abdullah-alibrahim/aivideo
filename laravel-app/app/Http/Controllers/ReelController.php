<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReelVideo;
use App\Models\ProviderSetting;
use App\Models\Reel;
use App\Services\GeminiContentService;
use App\Services\ProviderKeyRing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $query = Reel::orderBy('created_at', 'desc');

        if ($request->has('filter_lang') && in_array($request->filter_lang, ['ar', 'en'], true)) {
            $query->where('language', $request->filter_lang);
        }

        if ($request->has('filter_niche') && $request->filter_niche) {
            $query->where('niche', $request->filter_niche);
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

        return view('reels.index', compact('reels', 'lang', 'stats', 'backgrounds'));
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
        $elevenLabsKeys = $this->configuredElevenLabsKeys();
        $voiceoverAvailable = [
            'ar' => $elevenLabsKeys !== [] && filled($this->elevenLabsVoiceId('ar')),
            'en' => $elevenLabsKeys !== [] && filled($this->elevenLabsVoiceId('en')),
        ];

        return view('reels.wizard', compact('lang', 'backgrounds', 'voiceoverAvailable'));
    }

    public function store(Request $request): RedirectResponse
    {
        $backgrounds = $this->backgroundCatalog();
        $backgroundIds = array_column($backgrounds, 'id');

        $validated = $request->validate([
            'topic' => ['nullable', 'required_without:user_prompt', 'string', 'max:255'],
            'user_prompt' => ['nullable', 'required_without:topic', 'string', 'max:1000'],
            'title' => ['nullable', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:100'],
            'niche' => ['nullable', 'string', Rule::in(['money', 'habits', 'psychology', 'tech', 'business', 'productivity'])],
            'target_country' => ['nullable', Rule::in(['US', 'UK', 'Canada', 'Australia', 'Global'])],
            'goal' => ['nullable', 'string', Rule::in(['followers', 'saves', 'shares', 'comments'])],
            'template' => ['nullable', 'string', Rule::in(['classic', 'clean_news', 'cinematic', 'quote_card'])],
            'format_type' => ['nullable', Rule::in(['full_idea', 'quick_tip', 'list', 'story'])],
            'accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'style' => ['nullable', 'string', 'max:100'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'camera_motion' => ['nullable', Rule::in(['static', 'zoom_in', 'pan'])],
            'font_scale' => ['nullable', 'integer', 'min:80', 'max:130'],
            'background_dimming' => ['nullable', 'integer', 'min:0', 'max:80'],
            'duration' => ['nullable', 'numeric', 'min:3', 'max:60'],
            'gemini_keys' => ['nullable', 'string', 'max:5000'],
            'videos' => ['nullable', 'array', 'max:5'],
            'videos.*' => ['file', 'mimes:mp4,mov,avi,webm', 'max:'.((int) config('studio.limits.max_video_mb', 100) * 1024)],
            'bg_preset' => ['nullable', Rule::in($backgroundIds)],
            'music' => ['nullable', 'file', 'mimes:mp3,wav,m4a', 'max:'.((int) config('studio.limits.max_music_mb', 20) * 1024)],
            'voiceover_enabled' => ['nullable', 'boolean'],
            'voiceover_tone' => ['nullable', Rule::in(['neutral', 'calm', 'warm', 'energetic'])],
            'headline' => ['nullable', 'string', 'max:255'],
            'hook_headline' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:3000'],
            'hashtags' => ['nullable', 'string', 'max:1000'],
            'line1' => ['nullable', 'string', 'max:500'],
            'line2' => ['nullable', 'string', 'max:500'],
            'line3' => ['nullable', 'string', 'max:500'],
        ]);

        $userPrompt = $validated['user_prompt'] ?? null;
        $topic = trim($validated['topic'] ?? '') ?: Str::limit(trim((string) $userPrompt), 50, '');
        $sessionLanguage = session('app_locale');
        $language = $validated['language'] ?? (in_array($sessionLanguage, ['ar', 'en'], true) ? $sessionLanguage : 'ar');
        $voiceoverEnabled = $request->boolean('voiceover_enabled');
        $elevenLabsKeys = $this->configuredElevenLabsKeys();

        if ($voiceoverEnabled && ($elevenLabsKeys === [] || ! filled($this->elevenLabsVoiceId($language)))) {
            $message = $language === 'en'
                ? 'Add an active ElevenLabs key and an English Voice ID in Provider Key Manager to enable narration.'
                : 'أضف مفتاح ElevenLabs نشطاً وVoice ID عربياً من شاشة إدارة المفاتيح لتفعيل التعليق الصوتي.';

            return back()->withErrors(['voiceover_enabled' => $message])->withInput();
        }

        $niche = $validated['niche'] ?? 'productivity';
        $targetCountry = $validated['target_country'] ?? 'Global';
        $goal = $validated['goal'] ?? 'followers';
        $template = $validated['template'] ?? 'classic';
        $formatType = $validated['format_type'] ?? 'full_idea';
        $handle = trim((string) ($validated['handle'] ?? ''));
        $style = $validated['style'] ?? 'engaging';
        $aspectRatio = $validated['aspect_ratio'] ?? '9:16';
        $cameraMotion = $validated['camera_motion'] ?? 'zoom_in';
        $fontScale = (int) ($validated['font_scale'] ?? 100);
        $backgroundDimming = (int) ($validated['background_dimming'] ?? 35);
        $antiBorder = $request->boolean('anti_border');
        $accentColor = $validated['accent_color'] ?? '#fb7185';
        $title = ($validated['title'] ?? '') ?: (($language === 'en' ? 'Reel: ' : 'ريلز: ').$topic);
        $duration = (float) ($validated['duration'] ?? 10.0);
        $configuredGeminiKeys = json_decode((string) config('services.gemini.keys_json', '[]'), true);
        $configuredGeminiKeys = is_array($configuredGeminiKeys) ? $configuredGeminiKeys : [];
        $submittedGeminiKeys = array_map('trim', preg_split('/\r?\n/', (string) ($validated['gemini_keys'] ?? '')));
        $geminiKeys = array_values(array_unique(array_filter(
            array_merge($this->activeProviderKeys('gemini'), $configuredGeminiKeys, $submittedGeminiKeys),
            static fn ($key): bool => is_string($key) && trim($key) !== ''
        )));

        if ($geminiKeys === []) {
            $message = $language === 'en'
                ? 'Add an active Gemini API key before generating content. Local generation is disabled.'
                : 'أضف مفتاح Gemini API مفعّلاً قبل التوليد. التوليد المحلي معطّل.';

            return back()->withErrors(['gemini_keys' => $message])->withInput();
        }

        $localDisk = Storage::disk('local');
        $uploadedVideoPaths = [];
        $storedInputPaths = [];

        if ($request->hasFile('videos') && count($request->file('videos')) > 0) {
            foreach ($request->file('videos') as $file) {
                $path = $file->store('input_videos', 'local');
                if ($path) {
                    $storedInputPaths[] = $path;
                    $uploadedVideoPaths[] = $localDisk->path($path);
                }
            }
        } else {
            $backgroundId = $request->input('bg_preset') ?: ($backgrounds[0]['id'] ?? null);
            $selectedBackground = collect($backgrounds)->firstWhere('id', $backgroundId);

            if ($selectedBackground && is_file($selectedBackground['path'])) {
                $uploadedVideoPaths[] = $selectedBackground['path'];
            } else {
                $sampleSource = base_path('../python_engine/storage/input/sample.mp4');
                if (is_file($sampleSource)) {
                    $uploadedVideoPaths[] = $sampleSource;
                }
            }
        }

        if ($uploadedVideoPaths === []) {
            return redirect()->back()->with('error', 'يجب توفر مقطع فيديو للمعالجة.');
        }

        $musicPath = null;
        $storedMusicPath = null;
        if ($request->hasFile('music')) {
            $storedMusicPath = $request->file('music')->store('bg_music', 'local');
            if ($storedMusicPath) {
                $musicPath = $localDisk->path($storedMusicPath);
            }
        }

        $customHeadline = $validated['headline'] ?? $validated['hook_headline'] ?? null;
        $customPoints = [];
        for ($index = 1; $index <= 3; $index++) {
            $value = $validated['line'.$index] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $customPoints[] = trim($value);
            }
        }

        $reel = Reel::create([
            'title' => $title,
            'handle' => $handle,
            'topic' => $topic,
            'user_prompt' => $userPrompt,
            'niche' => $niche,
            'target_country' => $targetCountry,
            'goal' => $goal,
            'language' => $language,
            'style' => $style,
            'template' => $template,
            'format_type' => $formatType,
            'aspect_ratio' => $aspectRatio,
            'camera_motion' => $cameraMotion,
            'anti_border_warning' => $antiBorder,
            'duration' => $duration,
            'bg_music_path' => $storedMusicPath,
            'input_videos' => $storedInputPaths,
            'score' => null,
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
        ]);

        $outputRelativePath = 'reels/reel_'.$reel->id.'_'.time().'.mp4';
        Storage::disk('public')->makeDirectory('reels');

        $options = [
            'font_scale' => $fontScale,
            'background_dimming' => $backgroundDimming,
            'anti_border' => $antiBorder,
            'voiceover' => $voiceoverEnabled,
            'voiceover_tone' => $validated['voiceover_tone'] ?? 'neutral',
            'accent_color' => $accentColor,
            'custom_headline' => $customHeadline,
            'custom_points' => $customPoints,
            'caption' => trim((string) ($validated['caption'] ?? '')),
            'hashtags' => trim((string) ($validated['hashtags'] ?? '')),
            'gemini_keys' => $geminiKeys,
        ];

        try {
            GenerateReelVideo::dispatch($reel, $uploadedVideoPaths, $musicPath, $options, $outputRelativePath);
        } catch (\Throwable $exception) {
            logger()->error('Could not queue the reel render.', ['reel_id' => $reel->id, 'exception' => $exception->getMessage()]);
            $reel->forceFill([
                'status' => 'failed',
                'progress_stage' => 'queue_failed',
                'error_message' => trans('studio.errors.queue_failed', [], $language),
            ])->save();

            return redirect()->route('reels.index', ['lang' => $language])
                ->with('error', trans('studio.errors.queue_failed', [], $language));
        }

        return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $language])
            ->with('success', trans('studio.flash.queued', [], $language));
    }

    public function status(Reel $reel): JsonResponse
    {
        return response()->json([
            'status' => $reel->status,
            'progress' => (int) ($reel->progress ?? 0),
            'stage' => $reel->progress_stage,
            'error' => $reel->error_message,
            'ready' => $reel->status === 'completed',
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
        if ($this->isOwnedStoragePath($reel->output_video_path, ['reels'])
            && Storage::disk('public')->exists($reel->output_video_path)) {
            Storage::disk('public')->delete($reel->output_video_path);
        }

        $localFiles = array_merge($reel->input_videos ?? [], array_filter([$reel->bg_music_path]));
        foreach ($localFiles as $path) {
            if ($this->isOwnedStoragePath($path, ['input_videos', 'bg_music'])) {
                Storage::disk('local')->delete($path);
            }
        }

        $reel->delete();

        $lang = session('app_locale', 'ar');

        $message = $lang === 'en' ? 'Reel and its stored media were deleted.' : 'تم حذف الفيديو وملفاته المحفوظة.';

        return redirect()->route('reels.index', ['lang' => $lang])->with('success', $message);
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
        ]);
        try {
            $result = app(GeminiContentService::class)->generate($this->geminiPrompt(
                'Return JSON with key lines containing exactly three concise rewritten script lines. Apply the requested action.',
                ['action' => $input['action'] ?? 'punctuate', 'language' => $input['language'] ?? 'en', 'lines' => [$input['line1'] ?? '', $input['line2'] ?? '', $input['line3'] ?? '']]
            ));

            return response()->json(['status' => 'success', 'lines' => array_values($result['data']['lines'] ?? []), 'ai_model' => $result['model']]);
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
        $message = str_contains($exception->getMessage(), 'required')
            ? 'Gemini API key is required before generating content.'
            : 'Gemini could not generate this content. Check the active key or try again.';

        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], 502);
    }

    private function activeProviderKeys(string $provider): array
    {
        return app(ProviderKeyRing::class)->activeValues($provider);
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

    private function backgroundCatalog(): array
    {
        $directory = storage_path('app/public/backgrounds');

        return array_values(array_filter(array_map(function (array $background) use ($directory) {
            $videoPath = $directory.'/'.$background['file'];
            $posterPath = $directory.'/'.$background['poster'];

            if (! is_file($videoPath) || ! is_file($posterPath)) {
                return null;
            }

            return array_merge($background, [
                'path' => $videoPath,
                'poster_url' => asset('storage/backgrounds/'.$background['poster']),
            ]);
        }, config('reel-backgrounds', []))));
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
}
