<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReelVideo;
use App\Models\ProviderSetting;
use App\Models\Reel;
use App\Models\ReelCollection;
use App\Services\FactCheckService;
use App\Services\GeminiContentService;
use App\Services\ProviderKeyRing;
use App\Services\ReelQualityAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

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
            'line1' => ['nullable', 'string', 'max:500'],
            'line2' => ['nullable', 'string', 'max:500'],
            'line3' => ['nullable', 'string', 'max:500'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'template' => ['nullable', Rule::in(['classic', 'clean_news', 'cinematic', 'quote_card', 'bold_stripe'])],
            'handle' => ['nullable', 'string', 'max:100'],
            'accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'font_scale' => ['nullable', 'integer', 'min:80', 'max:130'],
            'quality' => ['nullable', Rule::in(['720', '1080'])],
            'captions' => ['nullable', 'boolean'],
            'auto_music' => ['nullable', 'boolean'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
        ]);

        $python = (string) (config('studio.engine.python_bin') ?: base_path('../venv/bin/python3'));
        $script = (string) (config('studio.engine.script') ?: base_path('../python_engine/smart_reels.py'));

        if (! is_file($python) || ! is_executable($python) || ! is_file($script)) {
            return response()->json([
                'status' => 'error',
                'message' => trans('studio.errors.engine_missing', [], $validated['language'] ?? 'ar'),
            ], 502);
        }

        Storage::disk('public')->makeDirectory('previews');
        foreach (Storage::disk('public')->files('previews') as $oldPreview) {
            if (Storage::disk('public')->lastModified($oldPreview) < now()->subHour()->timestamp) {
                Storage::disk('public')->delete($oldPreview);
            }
        }

        $relativePath = 'previews/'.Str::uuid().'.png';
        $absolutePath = storage_path('app/public/'.$relativePath);
        @mkdir(dirname($absolutePath), 0755, true);

        $headline = trim((string) ($validated['headline'] ?? ''));
        $points = [];
        foreach (['line1', 'line2', 'line3'] as $field) {
            $value = trim((string) ($validated[$field] ?? ''));
            if ($value !== '') {
                $points[] = $value;
            }
        }

        $command = [
            $python,
            $script,
            '--preview-image', $absolutePath,
            '--topic', $headline !== '' ? $headline : 'preview',
            '--language', (string) ($validated['language'] ?? 'ar'),
            '--template', (string) ($validated['template'] ?? 'classic'),
            '--handle', (string) ($validated['handle'] ?? ''),
            '--accent-color', (string) ($validated['accent_color'] ?? '#fb7185'),
            '--font-scale', (string) (int) ($validated['font_scale'] ?? 100),
            '--quality', (string) (int) ($validated['quality'] ?? 1080),
            '--aspect-ratio', (string) ($validated['aspect_ratio'] ?? '9:16'),
            '--anti-border',
        ];

        if ($headline !== '') {
            $command[] = '--custom-headline';
            $command[] = $headline;
        }

        if ($points !== []) {
            $command[] = '--custom-points';
            foreach ($points as $point) {
                $command[] = $point;
            }
        }

        $process = new Process($command, base_path('../'), [
            'AIVIDEO_ARABIC_FONT' => (string) config('studio.engine.arabic_font', ''),
        ]);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            logger()->warning('Preview render crashed.', ['error' => $exception->getMessage()]);
            @unlink($absolutePath);

            return response()->json(['status' => 'error', 'message' => 'Preview render timed out.'], 502);
        }

        if (! $process->isSuccessful() || ! is_file($absolutePath)) {
            logger()->warning('Preview render failed.', ['output' => Str::limit($process->getErrorOutput(), 800)]);
            @unlink($absolutePath);

            return response()->json(['status' => 'error', 'message' => 'Preview render failed.'], 502);
        }

        return response()->json([
            'status' => 'success',
            'url' => asset('storage/'.$relativePath),
        ]);
    }

    public function qualityCheck(Request $request, ReelQualityAnalyzer $analyzer): JsonResponse
    {
        $input = $request->validate([
            'headline' => ['nullable', 'string', 'max:255'],
            'hook_headline' => ['nullable', 'string', 'max:255'],
            'line1' => ['nullable', 'string', 'max:500'],
            'line2' => ['nullable', 'string', 'max:500'],
            'line3' => ['nullable', 'string', 'max:500'],
            'topic' => ['nullable', 'string', 'max:255'],
            'user_prompt' => ['nullable', 'string', 'max:1000'],
            'manual_content' => ['nullable', 'boolean'],
            'duration' => ['nullable', 'numeric', 'min:3', 'max:60'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
        ]);
        $input['custom_points'] = array_values(array_filter([
            $input['line1'] ?? null,
            $input['line2'] ?? null,
            $input['line3'] ?? null,
        ], static fn ($line): bool => is_string($line) && trim($line) !== ''));

        return response()->json([
            'status' => 'success',
            'report' => $analyzer->analyze($input),
        ]);
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
            return response()->json([
                'status' => 'error',
                'message' => ($input['language'] ?? 'ar') === 'en' ? 'Add a headline or script before checking.' : 'أضف الهوك أو النص قبل فحص الحقائق.',
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
            'manual_content' => ['nullable', 'boolean'],
            'generation_mode' => ['nullable', Rule::in(['single', 'variants', 'batch'])],
            'variant_count' => ['nullable', 'integer', 'min:3', 'max:5'],
            'batch_topics' => ['nullable', 'string', 'max:1500'],
            'title' => ['nullable', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:100'],
            'niche' => ['nullable', 'string', Rule::in(['money', 'habits', 'psychology', 'tech', 'business', 'productivity'])],
            'target_country' => ['nullable', Rule::in(['US', 'UK', 'Canada', 'Australia', 'Global'])],
            'goal' => ['nullable', 'string', Rule::in(['followers', 'saves', 'shares', 'comments'])],
            'template' => ['nullable', 'string', Rule::in(['classic', 'clean_news', 'cinematic', 'quote_card', 'bold_stripe'])],
            'format_type' => ['nullable', Rule::in(['full_idea', 'quick_tip', 'list', 'story'])],
            'accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'style' => ['nullable', 'string', 'max:100'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'quality' => ['nullable', Rule::in(['720', '1080'])],
            'captions' => ['nullable', 'boolean'],
            'auto_music' => ['nullable', 'boolean'],
            'camera_motion' => ['nullable', Rule::in(['static', 'zoom_in', 'pan', 'parallax'])],
            'text_motion' => ['nullable', Rule::in(['vertical', 'slide_right', 'slide_left'])],
            'scene_transition' => ['nullable', Rule::in(['template', 'fade', 'wipe', 'slide', 'varied'])],
            'motion_preset' => ['nullable', Rule::in(['custom', 'subtle_story', 'cinematic_depth', 'news_wipe', 'hook_focus', 'minimal'])],
            'hook_word_by_word' => ['nullable', 'boolean'],
            'highlight_important' => ['nullable', 'boolean'],
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

        $userPrompt = trim((string) ($validated['user_prompt'] ?? '')) ?: null;
        $manualContent = $request->boolean('manual_content');
        $generationMode = $validated['generation_mode'] ?? 'single';
        $batchTopics = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            preg_split('/\R/u', (string) ($validated['batch_topics'] ?? '')) ?: [],
        ), static fn (string $value): bool => $value !== ''));

        if ($generationMode === 'batch' && (count($batchTopics) < 2 || count($batchTopics) > 5)) {
            $message = ($validated['language'] ?? session('app_locale', 'ar')) === 'en'
                ? 'A batch needs between 2 and 5 non-empty topics, one per line.'
                : 'تتطلب الدفعة موضوعين إلى خمسة مواضيع غير فارغة، موضوعاً واحداً في كل سطر.';

            return back()->withErrors(['batch_topics' => $message])->withInput();
        }

        if ($generationMode === 'batch' && collect($batchTopics)->contains(static fn (string $value): bool => mb_strlen($value, 'UTF-8') > 255)) {
            $message = ($validated['language'] ?? session('app_locale', 'ar')) === 'en'
                ? 'Each batch topic must be 255 characters or fewer.'
                : 'يجب ألا يتجاوز كل موضوع في الدفعة 255 حرفاً.';

            return back()->withErrors(['batch_topics' => $message])->withInput();
        }

        if ($generationMode !== 'single' && $manualContent) {
            $message = ($validated['language'] ?? session('app_locale', 'ar')) === 'en'
                ? 'Multiple generation requires AI mode so each item can receive distinct content.'
                : 'يتطلب التوليد المتعدد وضع الذكاء الاصطناعي كي يحصل كل فيديو على محتوى مختلف.';

            return back()->withErrors(['generation_mode' => $message])->withInput();
        }

        $customHeadline = trim((string) ($validated['headline'] ?? $validated['hook_headline'] ?? ''));
        $customPoints = [];
        for ($index = 1; $index <= 3; $index++) {
            $value = $validated['line'.$index] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $customPoints[] = trim($value);
            }
        }

        if ($manualContent && ($customHeadline === '' || $customPoints === [])) {
            $message = ($validated['language'] ?? session('app_locale', 'ar')) === 'en'
                ? 'Manual mode needs a headline and at least one script line.'
                : 'يتطلب الوضع اليدوي عنواناً وسطر نص واحداً على الأقل.';

            return back()->withErrors(['manual_content' => $message])->withInput();
        }

        $topic = $generationMode === 'batch'
            ? $batchTopics[0]
            : trim((string) ($validated['topic'] ?? ''));
        $topic = $topic ?: ($manualContent ? $customHeadline : Str::limit((string) $userPrompt, 50, ''));

        if ($topic === '') {
            $message = ($validated['language'] ?? session('app_locale', 'ar')) === 'en'
                ? 'Enter a topic or a video brief before generating.'
                : 'أدخل موضوع الفيديو أو وصفه قبل التوليد.';

            return back()->withErrors(['topic' => $message])->withInput();
        }
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

        if (! $manualContent && $geminiKeys === []) {
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

        $options = [
            'font_scale' => $fontScale,
            'background_dimming' => $backgroundDimming,
            'anti_border' => $antiBorder,
            'voiceover' => $voiceoverEnabled,
            'voiceover_tone' => $validated['voiceover_tone'] ?? 'neutral',
            'accent_color' => $accentColor,
            'quality' => (int) ($validated['quality'] ?? 1080),
            'captions' => $request->has('captions') ? $request->boolean('captions') : true,
            'auto_music' => $request->has('auto_music') ? $request->boolean('auto_music') : true,
            'manual_content' => $manualContent,
            'motion_preset' => $validated['motion_preset'] ?? 'custom',
            'text_motion' => $validated['text_motion'] ?? 'vertical',
            'scene_transition' => $validated['scene_transition'] ?? 'template',
            'hook_word_by_word' => $request->boolean('hook_word_by_word'),
            'highlight_important' => $request->has('highlight_important')
                ? $request->boolean('highlight_important')
                : true,
            'bg_preset' => $request->input('bg_preset') ?: ($backgrounds[0]['id'] ?? null),
            'custom_headline' => $customHeadline,
            'custom_points' => $customPoints,
            'caption' => trim((string) ($validated['caption'] ?? '')),
            'hashtags' => trim((string) ($validated['hashtags'] ?? '')),
            'gemini_keys' => $geminiKeys,
        ];

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
        $qualityAnalyzer = app(ReelQualityAnalyzer::class);
        $variantTones = $language === 'en'
            ? ['direct and punchy', 'warm and encouraging', 'curiosity-led', 'calm and practical', 'story-driven']
            : ['مباشرة ومكثفة', 'دافئة ومشجعة', 'تعتمد على الفضول', 'هادئة وعملية', 'قصصية'];
        $durationOffsets = [-5, 0, 5, -3, 3];

        foreach ($itemTopics as $index => $itemTopic) {
            $itemDuration = $duration;
            $itemPrompt = $userPrompt ?? '';

            if ($generationMode === 'variants') {
                $itemDuration = min(60.0, max(3.0, $duration + $durationOffsets[$index]));
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
                'template' => $template,
                'format_type' => $formatType,
                'aspect_ratio' => $aspectRatio,
                'camera_motion' => $cameraMotion,
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
                'render_options' => array_diff_key($options, ['gemini_keys' => true]),
            ])->save();
            $createdReels[] = $reel;
        }

        Storage::disk('public')->makeDirectory('reels');
        $queuedCount = 0;
        foreach ($createdReels as $reel) {
            $outputRelativePath = 'reels/reel_'.$reel->id.'_'.time().'_'.Str::lower(Str::random(6)).'.mp4';
            try {
                GenerateReelVideo::dispatch($reel, $uploadedVideoPaths, $musicPath, $options, $outputRelativePath);
                $queuedCount++;
            } catch (\Throwable $exception) {
                logger()->error('Could not queue the reel render.', ['reel_id' => $reel->id, 'exception' => $exception->getMessage()]);
                $reel->forceFill([
                    'status' => 'failed',
                    'progress_stage' => 'queue_failed',
                    'error_message' => trans('studio.errors.queue_failed', [], $language),
                ])->save();
            }
        }

        if ($queuedCount === 0) {
            return redirect()->route('reels.index', ['lang' => $language])
                ->with('error', trans('studio.errors.queue_failed', [], $language));
        }

        $message = $generationMode === 'single'
            ? trans('studio.flash.queued', [], $language)
            : trans('studio.flash.collection_queued', ['count' => $queuedCount], $language);

        return redirect()->route('reels.index', ['active' => $createdReels[0]->id, 'lang' => $language])
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
            'template' => ['nullable', Rule::in(['classic', 'clean_news', 'cinematic', 'quote_card', 'bold_stripe'])],
            'format_type' => ['nullable', Rule::in(['full_idea', 'quick_tip', 'list', 'story'])],
            'niche' => ['nullable', 'string', Rule::in(['money', 'habits', 'psychology', 'tech', 'business', 'productivity'])],
            'target_country' => ['nullable', Rule::in(['US', 'UK', 'Canada', 'Australia', 'Global'])],
            'goal' => ['nullable', 'string', Rule::in(['followers', 'saves', 'shares', 'comments'])],
            'handle' => ['nullable', 'string', 'max:100'],
            'accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'duration' => ['nullable', 'numeric', 'min:3', 'max:60'],
            'bg_preset' => ['nullable', Rule::in($backgroundIds)],
            'videos' => ['nullable', 'array', 'max:5'],
            'videos.*' => ['file', 'mimes:mp4,mov,avi,webm', 'max:'.((int) config('studio.limits.max_video_mb', 100) * 1024)],
        ]);

        $sessionLanguage = session('app_locale');
        $language = $validated['language'] ?? (in_array($sessionLanguage, ['ar', 'en'], true) ? $sessionLanguage : 'ar');
        $ideaText = trim($validated['idea']);

        $configuredGeminiKeys = json_decode((string) config('services.gemini.keys_json', '[]'), true);
        $configuredGeminiKeys = is_array($configuredGeminiKeys) ? $configuredGeminiKeys : [];
        $geminiKeys = array_values(array_unique(array_filter(
            array_merge($this->activeProviderKeys('gemini'), $configuredGeminiKeys),
            static fn ($key): bool => is_string($key) && trim($key) !== ''
        )));

        if ($geminiKeys === []) {
            $message = $language === 'en'
                ? 'Add an active Gemini API key before generating a scenario. Local generation is disabled.'
                : 'أضف مفتاح Gemini API مفعّلاً قبل توليد السيناريو. التوليد المحلي معطّل.';

            return back()->withErrors(['idea' => $message])->withInput();
        }

        try {
            $result = app(GeminiContentService::class)->generate($this->geminiPrompt(
                'Return JSON with keys title,headline,points,caption,hashtags. Turn the plain idea into one complete short-video script: title is a short storage label, headline is one spoken opening hook of at most 12 words, points is exactly three short spoken scenes that build on the hook, caption is a ready-to-post social caption, and hashtags is one space-separated hashtag string. Keep every claim verifiable and write everything in the requested language.',
                [
                    'idea' => $ideaText,
                    'language' => $language,
                    'niche' => $validated['niche'] ?? 'productivity',
                    'goal' => $validated['goal'] ?? 'followers',
                    'target_country' => $validated['target_country'] ?? 'Global',
                    'duration_seconds' => (int) round((float) ($validated['duration'] ?? 10.0)),
                ]
            ));
            $script = $result['data'];
        } catch (\Throwable $exception) {
            return back()->withErrors(['idea' => trans('studio.errors.gemini_failed', [], $language)])->withInput();
        }

        $headline = trim((string) ($script['headline'] ?? ''));
        $points = array_values(array_filter(array_map(
            static fn ($point): string => trim((string) $point),
            array_slice(is_array($script['points'] ?? null) ? $script['points'] : [], 0, 3)
        ), static fn (string $point): bool => $point !== ''));
        $caption = trim((string) ($script['caption'] ?? ''));
        $hashtags = trim((string) ($script['hashtags'] ?? ''));
        $title = trim((string) ($script['title'] ?? ''))
            ?: (($language === 'en' ? 'Scenario: ' : 'سيناريو: ').Str::limit($ideaText, 60, ''));

        if ($headline === '' || $points === []) {
            $message = $language === 'en'
                ? 'Gemini returned an incomplete scenario. Try rephrasing the idea.'
                : 'أعاد Gemini سيناريو ناقصاً. جرّب إعادة صياغة الفكرة.';

            return back()->withErrors(['idea' => $message])->withInput();
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
            $backgroundId = $validated['bg_preset'] ?? ($backgrounds[0]['id'] ?? null);
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
            return redirect()->back()->withErrors(['idea' => 'يجب توفر مقطع فيديو للمعالجة.'])->withInput();
        }

        $duration = (float) ($validated['duration'] ?? 10.0);
        $options = [
            'font_scale' => 100,
            'background_dimming' => 35,
            'anti_border' => true,
            'voiceover' => false,
            'voiceover_tone' => 'neutral',
            'accent_color' => $validated['accent_color'] ?? '#fb7185',
            'quality' => 1080,
            'captions' => true,
            'auto_music' => true,
            'manual_content' => true,
            'motion_preset' => 'custom',
            'text_motion' => 'vertical',
            'scene_transition' => 'template',
            'hook_word_by_word' => true,
            'highlight_important' => true,
            'bg_preset' => $validated['bg_preset'] ?? null,
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
            'template' => $validated['template'] ?? 'bold_stripe',
            'format_type' => $validated['format_type'] ?? 'quick_tip',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'zoom_in',
            'anti_border_warning' => true,
            'duration' => $duration,
            'input_videos' => $storedInputPaths,
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
            'quality_report' => $qualityReport,
        ]);
        $reel->forceFill(['render_options' => $options])->save();

        Storage::disk('public')->makeDirectory('reels');
        $outputRelativePath = 'reels/reel_'.$reel->id.'_'.time().'_'.Str::lower(Str::random(6)).'.mp4';

        try {
            GenerateReelVideo::dispatch($reel, $uploadedVideoPaths, null, $options, $outputRelativePath);
        } catch (\Throwable $exception) {
            logger()->error('Could not queue the scenario render.', ['reel_id' => $reel->id, 'exception' => $exception->getMessage()]);
            $reel->forceFill([
                'status' => 'failed',
                'progress_stage' => 'queue_failed',
                'error_message' => trans('studio.errors.queue_failed', [], $language),
            ])->save();

            return redirect()->route('reels.index', ['lang' => $language])
                ->with('error', trans('studio.errors.queue_failed', [], $language));
        }

        return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $language])
            ->with('success', trans('studio.flash.scenario_queued', [], $language))
            ->with('scenario', [
                'headline' => $headline,
                'points' => $points,
                'caption' => $caption,
                'hashtags' => $hashtags,
                'ai_model' => $result['model'],
            ]);
    }

    public function status(Reel $reel): JsonResponse
    {
        return response()->json([
            'status' => $reel->status,
            'progress' => (int) ($reel->progress ?? 0),
            'stage' => $reel->progress_stage,
            'error' => $reel->error_message,
            'ready' => $reel->status === 'completed',
            'cancelled' => $reel->status === 'cancelled',
        ]);
    }

    public function cancel(Reel $reel): RedirectResponse
    {
        $language = in_array($reel->language, ['ar', 'en'], true) ? $reel->language : 'ar';

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

        $temporaryDirectory = storage_path('app/tmp');
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
        $language = in_array($reel->language, ['ar', 'en'], true) ? $reel->language : 'ar';

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

        $musicPath = $reel->bg_music_path && $disk->exists($reel->bg_music_path)
            ? $disk->path($reel->bg_music_path)
            : null;
        $outputRelativePath = 'reels/reel_'.$reel->id.'_'.time().'_'.Str::lower(Str::random(8)).'.mp4';
        if (filled($reel->output_video_path)) {
            Storage::disk('public')->delete($reel->output_video_path);
        }

        $reel->forceFill([
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
            'error_message' => null,
            'output_video_path' => null,
            'output_video_url' => null,
            'thumbnail_path' => null,
            'subtitle_path' => null,
        ])->save();

        $options['gemini_keys'] = $geminiKeys;
        GenerateReelVideo::dispatch($reel->fresh(), $inputPaths, $musicPath, $options, $outputRelativePath);

        return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $language])
            ->with('success', $language === 'en' ? 'The reel was queued for another attempt.' : 'تمت إعادة الفيديو إلى قائمة الانتظار.');
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
