<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReelWizardControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_wizard_renders_the_cinematic_editing_controls(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('reels.wizard', ['lang' => 'ar']));

        $response->assertOk();
        $response->assertSee('subtitle_preset', false);
        $response->assertSee('timeline_json', false);
        $response->assertSee('timelineEnabled', false);
        $response->assertSee('overlay_light_leak', false);
        $response->assertSee('music_mood', false);
        $response->assertSee('sfx_style', false);
        $response->assertSee("regeneratePart('hook')", false);
        $response->assertSee("regeneratePart('last_scene')", false);
        $response->assertSee("regeneratePart('cta')", false);
    }

    public function test_the_wizard_offers_the_cinematic_transition_modes(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('reels.wizard', ['lang' => 'en']));

        $response->assertOk();
        foreach (['zoom_blur', 'glitch', 'whip_pan', 'auto'] as $mode) {
            $response->assertSee($mode, false);
        }
    }

    public function test_the_wizard_uses_one_real_preview_target_and_does_not_seed_fake_script_text(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('reels.wizard', ['lang' => 'en']));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'id="realPreviewBtn"'));
        $this->assertSame(1, substr_count($html, 'id="realPreviewImage"'));
        $this->assertSame(1, substr_count($html, 'id="realPreviewStatus"'));
        $this->assertStringNotContainsString('value="Choose one clear action to begin."', $html);
    }

    public function test_draft_restoration_applies_saved_timeline_after_restoring_form_fields(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('reels.wizard', ['lang' => 'en']))
            ->assertOk()
            ->getContent();

        $fieldsRestorePosition = strpos($html, 'draftFields.forEach((field, index) => {');
        $timelineRestorePosition = strpos($html, "restoreTimelineStarts(document.getElementById('timelineJsonInput')?.value || '')");

        $this->assertNotFalse($fieldsRestorePosition);
        $this->assertNotFalse($timelineRestorePosition);
        $this->assertStringNotContainsString('restoreTimelineStarts(savedTimelineJson);', $html);
        $this->assertLessThan($timelineRestorePosition, $fieldsRestorePosition);
    }
}
