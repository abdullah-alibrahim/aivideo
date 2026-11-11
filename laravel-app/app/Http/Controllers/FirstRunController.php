<?php

namespace App\Http\Controllers;

use App\Models\ProviderApiKey;
use App\Services\FirstRunChecker;
use App\Services\FirstRunFixer;
use App\Services\ProviderConnectivityTester;
use App\Services\ProviderKeyRing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FirstRunController extends Controller
{
    public function index(FirstRunChecker $checker): View
    {
        $lang = in_array(request()->query('lang'), ['ar', 'en'], true) ? request()->query('lang') : 'ar';
        session(['app_locale' => $lang]);

        return view('first-run.index', [
            'lang' => $lang,
            'report' => $checker->run(),
        ]);
    }

    public function check(FirstRunChecker $checker): JsonResponse
    {
        return response()->json($checker->run());
    }

    public function fix(FirstRunFixer $fixer, FirstRunChecker $checker): JsonResponse
    {
        $result = $fixer->run();

        return response()->json([
            'steps' => $result['steps'],
            'all_passed' => $result['all_passed'],
            'report' => $checker->run(),
        ]);
    }

    /**
     * Save First Run provider keys, test the real connection immediately, and
     * return the refreshed report so the UI can advance automatically.
     */
    public function storeKeys(Request $request, ProviderConnectivityTester $connectivity, ProviderKeyRing $keyRing, FirstRunChecker $checker): JsonResponse
    {
        $validated = $request->validate([
            'gemini' => ['nullable', 'string', 'min:20', 'max:4096'],
            'elevenlabs' => ['nullable', 'string', 'min:8', 'max:4096'],
        ]);

        $results = [];

        foreach (['gemini', 'elevenlabs'] as $provider) {
            $secret = trim((string) ($validated[$provider] ?? ''));

            if ($secret === '') {
                continue;
            }

            $exists = ProviderApiKey::query()
                ->where('provider', $provider)
                ->get()
                ->contains(fn (ProviderApiKey $key): bool => hash_equals((string) $key->secret, $secret));

            if (! $exists) {
                ProviderApiKey::query()->create([
                    'provider' => $provider,
                    'label' => 'First run',
                    'secret' => $secret,
                    'is_active' => true,
                    'sort_order' => ProviderApiKey::query()->where('provider', $provider)->count(),
                ]);
            }

            $test = $provider === 'gemini'
                ? $connectivity->testGemini($secret)
                : $connectivity->testElevenLabs($secret);

            $test['ok']
                ? $keyRing->recordSuccess($provider, $secret)
                : $keyRing->recordFailure($provider, $secret, $test['detail']);

            $results[$provider] = [
                'ok' => $test['ok'],
                'state' => $test['state'],
                'detail' => $test['detail'],
            ];
        }

        return response()->json([
            'results' => $results,
            'report' => $checker->run(),
        ]);
    }
}
