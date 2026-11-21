<?php

namespace Tests\Feature;

use App\Services\GeminiContentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiVisionRequestTest extends TestCase
{
    public function test_it_sends_the_reference_image_as_inline_base64_data(): void
    {
        config([
            'services.gemini.keys_json' => json_encode(['vision-test-key']),
            'services.gemini.models' => ['gemini-2.5-flash'],
        ]);
        Http::fake([
            '*generateContent*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode(['headline' => 'Hook'])]]],
                ]],
            ], 200),
        ]);
        Http::preventStrayRequests();

        $path = tempnam(sys_get_temp_dir(), 'ref_').'.png';
        file_put_contents($path, 'fake-image-bytes');

        try {
            $result = app(GeminiContentService::class)->generateFromImage('Analyse this frame.', $path, 'image/png');
        } finally {
            @unlink($path);
        }

        $this->assertSame('Hook', $result['data']['headline']);
        $this->assertSame('gemini-2.5-flash', $result['model']);

        Http::assertSent(function (Request $request): bool {
            $parts = $request['contents'][0]['parts'] ?? [];

            return ($parts[0]['inlineData']['mimeType'] ?? null) === 'image/png'
                && ($parts[0]['inlineData']['data'] ?? null) === base64_encode('fake-image-bytes')
                && ($parts[1]['text'] ?? null) === 'Analyse this frame.'
                && ($request['generationConfig']['temperature'] ?? null) === 0.4;
        });
    }

    public function test_it_refuses_a_missing_reference_image_before_calling_gemini(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $this->expectException(\RuntimeException::class);

        app(GeminiContentService::class)->generateFromImage('Analyse', '/tmp/does-not-exist-reference.jpg');
    }
}
