<?php

namespace Tests\Feature;

use App\Services\FirstRunChecker;
use App\Services\ProviderConnectivityTester;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirstRunCheckTest extends TestCase
{
    public function test_connectivity_tester_pings_gemini_with_a_real_request(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['models' => []], 200),
        ]);

        $result = (new ProviderConnectivityTester)->testGemini('gemini-test-key');

        $this->assertTrue($result['ok']);
        $this->assertSame('connected', $result['state']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'key=gemini-test-key'));
    }

    public function test_connectivity_tester_reports_invalid_gemini_keys(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 400),
        ]);

        $result = (new ProviderConnectivityTester)->testGemini('bad-key');

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_key', $result['state']);
        $this->assertStringContainsString('API key not valid', $result['detail']);
    }

    public function test_connectivity_tester_pings_elevenlabs_with_the_api_key_header(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['subscription' => []], 200),
        ]);

        $result = (new ProviderConnectivityTester)->testElevenLabs('eleven-test-key');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn (Request $request): bool => ($request->header('xi-api-key')[0] ?? '') === 'eleven-test-key');
    }

    public function test_checker_reports_every_category_with_the_data_root(): void
    {
        config(['studio.data_dir' => sys_get_temp_dir().'/first-run-check-'.uniqid()]);

        $report = (new FirstRunChecker(new ProviderConnectivityTester))->run();

        $names = array_column($report['checks'], 'name');
        $this->assertContains('php', $names);
        $this->assertContains('database', $names);
        $this->assertContains('python', $names);
        $this->assertContains('ffmpeg', $names);
        $this->assertContains('storage', $names);
        $this->assertContains('permissions', $names);
        $this->assertContains('gemini', $names);
        $this->assertContains('elevenlabs', $names);
        $this->assertArrayHasKey('all_passed', $report);
    }

    public function test_first_run_page_renders(): void
    {
        $this->get(route('first-run'))
            ->assertOk()
            ->assertViewHas('report');
    }

    public function test_first_run_check_endpoint_returns_json(): void
    {
        $this->getJson(route('first-run.check'))
            ->assertOk()
            ->assertJsonStructure(['checks', 'all_passed']);
    }

    public function test_storing_keys_saves_and_tests_the_connection_automatically(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['models' => []], 200),
            'api.elevenlabs.io/*' => Http::response(['subscription' => []], 200),
        ]);

        $response = $this->postJson(route('first-run.keys'), [
            'gemini' => 'AIzaFirstRunValidKey000000',
            'elevenlabs' => 'eleven_first_run_key_001',
        ]);

        $response->assertOk()
            ->assertJsonPath('results.gemini.ok', true)
            ->assertJsonPath('results.elevenlabs.ok', true);

        $this->assertDatabaseHas('provider_api_keys', ['provider' => 'gemini', 'label' => 'First run']);
        $this->assertDatabaseHas('provider_api_keys', ['provider' => 'elevenlabs', 'label' => 'First run']);
    }
}
