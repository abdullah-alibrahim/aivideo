<?php

namespace App\Http\Controllers;

use App\Models\ProviderSetting;
use App\Models\Reel;
use App\Services\ProviderKeyRing;
use App\Services\GeminiContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
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
        set_time_limit(0);
        ignore_user_abort(true);

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
        if ($voiceoverEnabled && (
            $elevenLabsKeys === []
            || ! filled($this->elevenLabsVoiceId($language))
        )) {
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

        // Create initial pending Reel record
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
            'score' => 95,
            'status' => 'processing',
            'progress' => 5,
            'progress_stage' => 'queued',
        ]);

        $renderLock = Cache::lock('studio:render:global', (int) config('studio.limits.render_lock_seconds', 900));
        if (! $renderLock->get()) {
            $reel->update([
                'status' => 'failed',
                'error_message' => 'Another render is already running. Please retry shortly.',
            ]);

            return redirect()->back()->with('error', 'يوجد تصدير آخر قيد التنفيذ؛ حاول بعد انتهائه.');
        }

        $reel->update(['progress' => 15, 'progress_stage' => 'preparing_inputs']);

        $baseDir = base_path('../');
        $pythonBin = $baseDir.'/venv/bin/python3';
        $scriptPath = $baseDir.'/python_engine/smart_reels.py';

        $uploadedVideoPaths = [];
        $storedInputPaths = [];
        $localDisk = Storage::disk('local');

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
                // Keep a single deterministic fallback; never concatenate a sample with a stock loop.
                $sampleSource = $baseDir.'/python_engine/storage/input/sample.mp4';
                if (is_file($sampleSource)) {
                    $uploadedVideoPaths[] = $sampleSource;
                }
            }
        }

        if (empty($uploadedVideoPaths)) {
            $renderLock->release();
            $reel->update([
                'status' => 'failed',
                'error_message' => 'No video clip available.',
            ]);

            return redirect()->back()->with('error', 'يجب توفر مقطع فيديو للمعالجة.');
        }

        // Optional music
        $musicPath = null;
        if ($request->hasFile('music')) {
            $mPath = $request->file('music')->store('bg_music', 'local');
            if ($mPath) {
                $musicPath = $localDisk->path($mPath);
                $reel->bg_music_path = $mPath;
            }
        }

        $outputFilename = 'reel_'.$reel->id.'_'.time().'.mp4';
        $relativeOutputDir = 'reels';
        $relativeOutputPath = $relativeOutputDir.'/'.$outputFilename;
        $absoluteOutputPath = storage_path('app/public/'.$relativeOutputPath);

        if (! file_exists(dirname($absoluteOutputPath))) {
            mkdir(dirname($absoluteOutputPath), 0755, true);
        }

        $cmd = [
            $pythonBin,
            $scriptPath,
            '--topic', $topic,
            '--user-prompt', $userPrompt ?? '',
            '--output', $absoluteOutputPath,
            '--duration', (string) $duration,
            '--language', $language,
            '--niche', $niche,
            '--goal', $goal,
            '--target-country', $targetCountry,
            '--format-type', $formatType,
            '--style', $style,
            '--template', $template,
            '--handle', $handle,
            '--aspect-ratio', $aspectRatio,
            '--camera-motion', $cameraMotion,
            '--font-scale', (string) $fontScale,
            '--background-dimming', (string) $backgroundDimming,
            '--json',
        ];

        if ($antiBorder) {
            $cmd[] = '--anti-border';
        }

        if ($voiceoverEnabled) {
            $cmd[] = '--voiceover';
            $cmd[] = '--voiceover-tone';
            $cmd[] = $validated['voiceover_tone'] ?? 'neutral';
        }

        $cmd[] = '--input';
        foreach ($uploadedVideoPaths as $vp) {
            $cmd[] = $vp;
        }

        if ($musicPath) {
            $cmd[] = '--music';
            $cmd[] = $musicPath;
        }

        // Custom headline from wizard hook or topic
        $customHeadline = $validated['headline'] ?? $validated['hook_headline'] ?? null;
        if ($customHeadline) {
            $cmd[] = '--custom-headline';
            $cmd[] = $customHeadline;
        }

        if ($accentColor) {
            $cmd[] = '--accent-color';
            $cmd[] = $accentColor;
        }

        // The wizard currently exposes three editable content lines.
        $customPoints = [];
        for ($i = 1; $i <= 3; $i++) {
            $val = $validated['line'.$i] ?? null;
            if ($val && trim($val) !== '') {
                $customPoints[] = trim($val);
            }
        }
        if (! empty($customPoints)) {
            $cmd[] = '--custom-points';
            foreach ($customPoints as $pt) {
                $cmd[] = $pt;
            }
        }

        $geminiKeys = array_values(array_unique(array_filter(array_merge(
            app(ProviderKeyRing::class)->rotatedValues('gemini'),
            $configuredGeminiKeys,
            $submittedGeminiKeys,
        ), static fn ($key): bool => is_string($key) && trim($key) !== '')));
        $elevenLabsKeys = $voiceoverEnabled
            ? array_values(array_unique(array_filter(array_merge(
                app(ProviderKeyRing::class)->rotatedValues('elevenlabs'),
                [(string) config('services.elevenlabs.api_key', '')],
            ), static fn ($key): bool => is_string($key) && trim($key) !== '')))
            : $this->configuredElevenLabsKeys();
        $voiceIds = [
            'ar' => $this->elevenLabsVoiceId('ar'),
            'en' => $this->elevenLabsVoiceId('en'),
        ];

        $process = new Process(
            $cmd,
            $baseDir,
            [
                'AIVIDEO_GEMINI_KEYS_JSON' => json_encode($geminiKeys, JSON_THROW_ON_ERROR),
                'AIVIDEO_ELEVENLABS_KEYS_JSON' => json_encode($elevenLabsKeys, JSON_THROW_ON_ERROR),
                'GEMINI_API_KEY' => '',
                'GOOGLE_API_KEY' => '',
                'ELEVENLABS_API_KEY' => (string) config('services.elevenlabs.api_key', ''),
                'ELEVENLABS_VOICE_ID_AR' => $voiceIds['ar'],
                'ELEVENLABS_VOICE_ID_EN' => $voiceIds['en'],
                'PYTHON_DOTENV_DISABLED' => '1',
            ],
        );
        $process->setTimeout(600);
        try {
            $reel->update(['progress' => 35, 'progress_stage' => 'generating_with_gemini']);
            $process->run();
        } catch (\Throwable $exception) {
            $renderLock->release();
            @unlink($absoluteOutputPath);

            $reel->update([
                'status' => 'failed',
                'progress_stage' => 'render_failed',
                'error_message' => 'The video renderer could not complete the job.',
                'input_videos' => $storedInputPaths,
            ]);

            return redirect()->route('reels.index', ['lang' => $language])
                ->with('error', 'تعذّر إكمال تصدير الفيديو. تحقّق من ملفات المصدر وحاول مرة أخرى.');
        }
        $renderLock->release();

        if (! $process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput() ?: $process->getOutput();
            foreach (array_filter(array_merge($geminiKeys, $elevenLabsKeys, [config('services.elevenlabs.api_key')])) as $secret) {
                $errorOutput = str_replace($secret, '[redacted API key]', $errorOutput);
            }
            $errorOutput = preg_replace('/\b(?:sk-(?:proj-)?[A-Za-z0-9_-]{8,}|AIza[0-9A-Za-z_-]{20,})\b/i', '[redacted API key]', $errorOutput) ?? $errorOutput;

            logger()->warning('Video render process failed.', [
                'reel_id' => $reel->id,
                'exit_code' => $process->getExitCode(),
                'renderer_output' => Str::limit($errorOutput, 1200),
            ]);

            $reel->update([
                'status' => 'failed',
                'progress_stage' => str_contains($errorOutput, 'Gemini') ? 'gemini_failed' : 'render_failed',
                'error_message' => 'The video renderer could not complete the job. Details are available in the application log.',
                'input_videos' => $storedInputPaths,
            ]);

            @unlink($absoluteOutputPath);

            $userError = str_contains($errorOutput, 'Gemini')
                ? 'فشل توليد المحتوى عبر Gemini. تحقق من المفتاح أو جرّب مفتاحاً آخر.'
                : 'تعذّر تصدير الفيديو. لم يتم حفظ نتيجة ناقصة؛ راجع السجل وحاول مرة أخرى.';

            return redirect()->route('reels.index', ['lang' => $language])->with('error', $userError);
        }

        if (! is_file($absoluteOutputPath) || (filesize($absoluteOutputPath) ?: 0) < 1024) {
            $reel->update([
                'status' => 'failed',
                'error_message' => 'The video renderer finished without producing a usable MP4 file.',
                'input_videos' => $storedInputPaths,
            ]);

            @unlink($absoluteOutputPath);

            return redirect()->route('reels.index', ['lang' => $language])
                ->with('error', 'لم ينتج المحرك ملف فيديو صالحاً. تأكد من سلامة مقطع المصدر وحاول مرة أخرى.');
        }

        $outputStr = $process->getOutput();
        $generatedScript = null;
        $caption = null;
        $hashtags = null;
        $score = 95;
        $markerPosition = strpos($outputStr, '__JSON_OUTPUT__');
        $jsonPart = $markerPosition === false
            ? null
            : substr($outputStr, $markerPosition + strlen('__JSON_OUTPUT__'));
        $data = is_string($jsonPart) ? json_decode(trim($jsonPart), true) : null;

        if (! is_array($data) || ($data['status'] ?? null) !== 'success') {
            Storage::disk('public')->delete($relativeOutputPath);
            $reel->update([
                'status' => 'failed',
                'error_message' => 'The video renderer returned an invalid result.',
                'input_videos' => $storedInputPaths,
            ]);

            return redirect()->route('reels.index', ['lang' => $language])
                ->with('error', 'اكتمل الرندر دون نتيجة بيانات صالحة؛ لم يُحفظ الفيديو في المكتبة.');
        }

        if (isset($data['script'])) {
            $generatedScript = Str::limit(str_replace('*', '', (string) $data['script']), 10000, '');
        }
        $userCaption = trim((string) ($validated['caption'] ?? ''));
        $userHashtags = trim((string) ($validated['hashtags'] ?? ''));
        $caption = $userCaption !== '' ? $userCaption : ($data['caption'] ?? null);
        $hashtags = $userHashtags !== '' ? $userHashtags : ($data['hashtags'] ?? null);
        $score = max(0, min(100, (int) ($data['score'] ?? 95)));

        $reel->update([
            'status' => 'completed',
            'progress' => 100,
            'progress_stage' => 'completed',
            'output_video_path' => $relativeOutputPath,
            'output_video_url' => asset('storage/'.$relativeOutputPath),
            'generated_script' => $generatedScript,
            'caption' => $caption,
            'hashtags' => $hashtags,
            'content_source' => $data['content_source'] ?? 'gemini',
            'ai_model' => $data['ai_model'] ?? null,
            'score' => $score,
            'duration' => isset($data['duration']) ? max($duration, (float) $data['duration']) : $duration,
            'input_videos' => $storedInputPaths,
        ]);

        return redirect()->route('reels.index', ['active' => $reel->id, 'lang' => $language])
            ->with('success', $language === 'en' ? 'Video, caption, and hashtags created successfully.' : 'تم إنشاء الفيديو والكابشن والهاشتاغات بنجاح.');
    }

    public function show(Reel $reel)
    {
        return response()->json($reel);
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
        $language = $input['language'] ?? 'en';
        $subject = trim($input['topic'] ?? '') ?: trim($input['prompt'] ?? '');
        $subject = Str::limit($subject !== '' ? $subject : ($language === 'ar' ? 'موضوعك' : 'your topic'), 80, '');
        $isArabic = $language === 'ar';
        $idea = [
            'badge' => $isArabic ? 'قالب اقتراح' : 'Template suggestion',
            'title' => $isArabic ? '3 خطوات عملية حول '.$subject : '3 Practical Steps for '.$subject,
            'desc' => $isArabic ? 'حوّل الموضوع الذي أدخلته إلى ثلاث خطوات واضحة يمكن شرحها في فيديو قصير.' : 'Turn your topic into three clear steps that fit a short video.',
            'why' => $isArabic ? 'الاقتراح مبني على موضوعك، وليس على بيانات بحث أو توقعات انتشار.' : 'This suggestion uses your topic; it is not based on search or reach data.',
            'angle' => $isArabic ? 'قائمة موجزة: فكرة واحدة ثم ثلاث خطوات قابلة للتخصيص.' : 'A concise list: one idea followed by three steps you can customize.',
            'hook' => $isArabic ? '3 خطوات بسيطة للبدء في '.$subject : '3 Simple Steps to Get Started with '.$subject,
            'hook_desc' => $isArabic ? 'عنوان تمهيدي قابل للتعديل؛ راجع دقته قبل النشر.' : 'An editable starter headline; verify its accuracy before publishing.',
        ];

        return response()->json([
            'status' => 'success',
            'idea' => $idea,
        ]);
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
        $language = $input['language'] ?? 'en';
        $topic = trim($input['topic'] ?? '') ?: ($language === 'ar' ? 'موضوعك' : 'your topic');
        $topic = Str::limit($topic, 70, '');
        $hooks = $language === 'ar'
            ? [
                ['badge' => 'عنوان عملي', 'headline' => '3 خطوات بسيطة للبدء في '.$topic, 'desc' => 'عنوان تمهيدي مرتبط بموضوعك؛ عدّله ليتطابق مع محتوى الفيديو.'],
                ['badge' => 'قائمة قصيرة', 'headline' => '3 نقاط مهمّة عن '.$topic, 'desc' => 'صيغة قائمة موجزة من دون ادعاء نتيجة أو وعد غير متحقق.'],
                ['badge' => 'سؤال تمهيدي', 'headline' => 'ما الذي يجب معرفته عن '.$topic.'؟', 'desc' => 'سؤال مباشر يفتح الموضوع ويحتاج إلى إجابة دقيقة في الفيديو.'],
            ]
            : [
                ['badge' => 'Practical', 'headline' => '3 Simple Steps to Get Started with '.$topic, 'desc' => 'An editable title tied to your topic; make sure the video delivers on it.'],
                ['badge' => 'Short list', 'headline' => '3 Things to Know About '.$topic, 'desc' => 'A concise list format without promising an unverified result.'],
                ['badge' => 'Question', 'headline' => 'What Should You Know About '.$topic.'?', 'desc' => 'A direct question that the video should answer accurately.'],
            ];

        return response()->json([
            'status' => 'success',
            'hook' => $hooks[array_rand($hooks)],
        ]);
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
        $action = $input['action'] ?? 'punctuate';
        $isArabic = ($input['language'] ?? 'en') === 'ar';
        $defaults = $isArabic
            ? ['ابدأ بخطوة واحدة واضحة.', 'قسّم الفكرة إلى نقاط قصيرة.', 'اختم بسؤال يساعد المشاهد على التفكير.']
            : ['Start with one clear step.', 'Break the idea into short points.', 'End with a useful question.'];
        $lines = [];

        foreach (['line1', 'line2', 'line3'] as $index => $field) {
            $line = trim($input[$field] ?? '') ?: $defaults[$index];
            if ($action === 'simple') {
                $line = trim(str_replace('*', '', $line));
            } elseif ($action === 'shorten') {
                $words = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $line = implode(' ', array_slice($words, 0, 9));
            } elseif ($action === 'punctuate' && ! preg_match('/[.!?؟!]$/u', $line)) {
                $line .= $isArabic ? '!' : '!';
            }
            $lines[] = $line;
        }

        return response()->json([
            'status' => 'success',
            'lines' => $lines,
        ]);
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
        $style = $input['style'] ?? 'primary';
        $isArabic = ($input['language'] ?? 'en') === 'ar';
        $headline = trim($input['headline'] ?? '') ?: ($isArabic ? 'فكرة الفيديو' : 'your video idea');
        $headline = Str::limit($headline, 255, '');
        $names = $isArabic
            ? ['primary' => 'الأساسي', 'short' => 'مختصر', 'curiosity' => 'سؤال', 'educational' => 'تعليمي', 'story' => 'تأملي']
            : ['primary' => 'Primary', 'short' => 'Short', 'curiosity' => 'Question', 'educational' => 'Educational', 'story' => 'Reflective'];
        $captions = $isArabic
            ? [
                'primary' => $headline."\n\nاحفظ هذا الدليل للرجوع إليه. ما الخطوة التي ستبدأ بها؟",
                'short' => $headline."\n\nاحفظها لوقت لاحق.",
                'curiosity' => $headline."\n\nما السؤال الذي ترغب أن يجيب عنه هذا الفيديو؟",
                'educational' => $headline."\n\nابدأ بفكرة واحدة، ثم راجع كل خطوة وتأكد من دقتها قبل تطبيقها.",
                'story' => $headline."\n\nما التجربة التي مررت بها مع هذا الموضوع؟ شاركها في تعليق.",
            ]
            : [
                'primary' => $headline."\n\nSave this guide for later. Which step will you try first?",
                'short' => $headline."\n\nSave this for later.",
                'curiosity' => $headline."\n\nWhat question would you like this video to answer?",
                'educational' => $headline."\n\nStart with one idea, then review each step and verify it before acting.",
                'story' => $headline."\n\nWhat has your experience with this topic been? Share it in a comment.",
            ];
        $hashtags = $isArabic
            ? '#نصائح #تعلم #معلومات #ريلز #محتوى'
            : '#Tips #Learn #HowTo #Reels #Video';

        return response()->json([
            'status' => 'success',
            'data' => [
                'name' => $names[$style],
                'caption' => $captions[$style],
                'hashtags' => $hashtags,
            ],
        ]);
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
