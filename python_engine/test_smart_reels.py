import unittest
import json
import tempfile
import numpy as np
from pathlib import Path
import sys
from unittest.mock import MagicMock, patch
from moviepy import AudioClip

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parent))
import smart_reels as engine  # noqa: E402
from smart_reels import (  # noqa: E402
    _vertical_reveal_mask_frame,
    _staged_text_reveal_mask_frame,
    animate_reference_overlay,
    render_reference_card,
    _normalize_ai_package,
    _display_word,
    narration_text_from_package,
    generate_elevenlabs_voiceover,
    validate_input_video,
    apply_scene_transition,
    build_smoke_overlay,
    generate_ai_package,
)


class VerticalRevealAnimationTest(unittest.TestCase):
    def test_input_video_validation_rejects_missing_files(self):
        error = validate_input_video("/tmp/aivideo-file-that-does-not-exist.mp4")
        self.assertIn("does not exist", error)

    def test_input_video_validation_decodes_a_valid_clip(self):
        source = Path(__file__).resolve().parents[1] / "laravel-app/storage/app/public/backgrounds/clean_studio.mp4"
        self.assertIsNone(validate_input_video(source))

    def test_still_background_is_valid_and_becomes_a_timed_clip(self):
        source = Path(__file__).resolve().parents[1] / "laravel-app/public/backgrounds/rainy_city_street.jpg"
        self.assertIsNone(validate_input_video(source))

        clip = engine.process_motion_clip(source, 2.0, resolution=(90, 160), motion="static")
        self.assertAlmostEqual(clip.duration, 2.0)
        self.assertEqual(clip.get_frame(0.5).shape, (160, 90, 3))
        clip.close()

    def test_smoke_overlay_has_soft_animated_alpha(self):
        smoke = build_smoke_overlay((180, 320), 2.0, seed=7, opacity=0.2)
        first = smoke.mask.get_frame(0.1)
        later = smoke.mask.get_frame(1.4)
        self.assertEqual(first.shape, (320, 180))
        self.assertGreater(float(first.max()), 0.0)
        self.assertLessEqual(float(first.max()), 0.21)
        self.assertFalse(np.array_equal(first, later))
        smoke.close()

    def test_scene_transition_fades_in_and_out(self):
        overlay = render_reference_card(
            package={"headline": "Transition test"},
            size=(180, 320),
            duration=2,
            template="classic",
            anti_border=False,
        )
        transitioned = apply_scene_transition(overlay, 2, fade_in=0.2, fade_out=0.2)
        self.assertLess(float(transitioned.mask.get_frame(0.0).max()), 0.01)
        self.assertGreater(float(transitioned.mask.get_frame(1.0).max()), 0.9)
        self.assertLess(float(transitioned.mask.get_frame(1.99).max()), 0.1)
        transitioned.close()
        overlay.close()

    def test_horizontal_reveal_masks_move_in_from_both_sides(self):
        base = np.ones((24, 120), dtype=np.float32)
        for direction in ("right", "left"):
            start = engine._horizontal_reveal_mask_frame(base, 0.0, direction, 0.5, 8)
            middle = engine._horizontal_reveal_mask_frame(base, 0.25, direction, 0.5, 8)
            finished = engine._horizontal_reveal_mask_frame(base, 1.0, direction, 0.5, 8)
            self.assertLess(float(start.mean()), 0.1)
            self.assertGreater(float(middle.mean()), float(start.mean()))
            np.testing.assert_allclose(finished, base)

    def test_slide_and_wipe_scene_transitions_are_applied(self):
        overlay = render_reference_card(
            package={"headline": "Transition test"},
            size=(180, 320),
            duration=2,
            template="classic",
            anti_border=False,
        )
        slide = apply_scene_transition(overlay, 2, 0.2, 0.2, transition="slide", direction="right")
        self.assertGreater(slide.pos(0)[0], slide.pos(0.5)[0])
        wipe = apply_scene_transition(overlay, 2, 0.2, 0.2, transition="wipe", direction="right")
        self.assertGreater(float(wipe.mask.get_frame(0.5).max()), 0.9)
        self.assertLess(float(wipe.mask.get_frame(1.99).max()), 0.1)
        slide.close()
        wipe.close()
        overlay.close()

    def test_hook_word_by_word_builds_progressive_word_clips(self):
        clips = engine.build_caption_clips(
            {"kind": "hook", "text": "Make every word count", "caption": ""},
            0.0,
            2.0,
            (180, 320),
            "en",
            "#fb7185",
            hook_word_by_word=True,
        )
        self.assertEqual(len(clips), 4)
        self.assertEqual([clip.start for clip in clips], sorted(clip.start for clip in clips))
        for clip in clips:
            clip.close()

    def test_gemini_content_is_marked_as_api_generated(self):
        with patch.object(engine, "_try_gemini_key", return_value={
            "headline": "API headline",
            "point_1": "First API point",
            "point_2": "Second API point",
            "point_3": "Third API point",
            "caption": "API caption",
            "hashtags": ["#api"],
        }) as provider:
            package = generate_ai_package("API topic", gemini_api_keys=["AIza-test-key"])

        self.assertEqual(package["content_source"], "gemini")
        self.assertEqual(package["headline"], "API headline")
        provider.assert_called_once()

    def test_generation_without_gemini_key_has_no_local_fallback(self):
        with patch.dict(engine.os.environ, {
            "AIVIDEO_GEMINI_KEYS_JSON": "[]",
            "GEMINI_API_KEY": "",
            "GOOGLE_API_KEY": "",
        }, clear=False):
            with self.assertRaisesRegex(RuntimeError, "Gemini API key is required"):
                generate_ai_package("No local fallback", gemini_api_keys=[])

    def test_audio_fades_keep_duration_and_smooth_the_edges(self):
        def constant_stereo(t):
            values = np.asarray(t)
            if values.ndim == 0:
                return np.array([1.0, 1.0])
            return np.ones((len(values), 2), dtype=float)

        source = AudioClip(constant_stereo, duration=1.0, fps=20)
        faded = engine.fade_audio_edges(source, fade_in=0.2, fade_out=0.3)

        self.assertAlmostEqual(faded.duration, 1.0)
        self.assertAlmostEqual(float(np.max(faded.get_frame(0.0))), 0.0, places=5)
        self.assertGreater(float(np.max(faded.get_frame(0.5))), 0.95)
        self.assertLess(float(np.max(faded.get_frame(0.99))), 0.1)
        faded.close()
        source.close()

    def test_arabic_words_are_shaped_even_when_content_language_is_english(self):
        self.assertNotEqual(_display_word("العربية", "en"), "العربية")
        self.assertEqual(_display_word("English", "ar"), "English")

    def test_narration_text_removes_numbered_list_prefixes(self):
        text = narration_text_from_package({
            "headline": "ثلاث عادات مالية",
            "point_1": "1. ادخر أولاً",
            "point_2": "2) راقب مصروفك",
            "point_3": "",
        })

        self.assertEqual(text, "ثلاث عادات مالية ادخر أولاً راقب مصروفك")

    def test_elevenlabs_audio_request_uses_server_key_and_writes_binary_response(self):
        response = MagicMock()
        response.__enter__.return_value.read.return_value = b"fake-mp3-bytes"
        with tempfile.TemporaryDirectory() as directory:
            output_path = str(Path(directory) / "voice.mp3")
            with patch.object(engine.urllib.request, "urlopen", return_value=response) as urlopen:
                generate_elevenlabs_voiceover("مرحبا", "server-secret", "voice-123", output_path, tone="warm")

            request = urlopen.call_args.args[0]
            payload = json.loads(request.data.decode("utf-8"))
            self.assertEqual(request.get_header("Xi-api-key"), "server-secret")
            self.assertIn("voice-123", request.full_url)
            self.assertEqual(payload["text"], "مرحبا")
            self.assertEqual(payload["model_id"], "eleven_multilingual_v2")
            self.assertEqual(Path(output_path).read_bytes(), b"fake-mp3-bytes")

    def test_elevenlabs_rotates_to_next_key_after_a_rate_limit(self):
        response = MagicMock()
        response.__enter__.return_value.read.return_value = b"backup-mp3-bytes"
        rate_limit = engine.urllib.error.HTTPError("https://example.test", 429, "rate limited", {}, None)
        with tempfile.TemporaryDirectory() as directory:
            output_path = str(Path(directory) / "voice.mp3")
            with patch.object(engine.urllib.request, "urlopen", side_effect=[rate_limit, response]) as urlopen:
                generate_elevenlabs_voiceover("مرحبا", ["first-secret", "backup-secret"], "voice-123", output_path)

            requests = [call.args[0] for call in urlopen.call_args_list]
            self.assertEqual([request.get_header("Xi-api-key") for request in requests], ["first-secret", "backup-secret"])
            self.assertEqual(Path(output_path).read_bytes(), b"backup-mp3-bytes")

    def test_mask_reveals_from_top_to_bottom_and_finishes_fully_visible(self):
        base_mask = np.ones((100, 40), dtype=np.float32)
        start = _vertical_reveal_mask_frame(
            base_mask, time=0, start_y=0, end_y=110, delay=0.1, reveal_duration=1, feather=10
        )
        middle = _vertical_reveal_mask_frame(
            base_mask, time=0.5, start_y=0, end_y=110, delay=0.1, reveal_duration=1, feather=10
        )
        finished = _vertical_reveal_mask_frame(
            base_mask, time=2, start_y=0, end_y=110, delay=0.1, reveal_duration=1, feather=10
        )

        self.assertEqual(start.shape, base_mask.shape)
        self.assertEqual(float(start[20, 0]), 0.0)
        self.assertGreater(float(middle[50, 0]), 0.99)
        self.assertEqual(float(middle[99, 0]), 0.0)
        np.testing.assert_array_equal(finished, base_mask)

    def test_overlay_animation_preserves_duration_alpha_and_settles_in_place(self):
        overlay = render_reference_card(
            package={
                "headline": "A clear headline",
                "point_1": "1. First useful idea",
                "point_2": "2. Second useful idea",
                "point_3": "3. Save this reminder",
            },
            size=(180, 320),
            duration=3,
            template="classic",
            anti_border=False,
        )
        original_mask = overlay.mask.get_frame(0).copy()
        animated = animate_reference_overlay(overlay, 3)

        self.assertEqual(animated.duration, 3)
        self.assertIsNotNone(animated.mask)
        self.assertGreater(animated.pos(0)[1], animated.pos(1)[1])
        self.assertEqual(animated.pos(1)[1], 0)
        np.testing.assert_allclose(animated.mask.get_frame(2.9), original_mask, atol=1e-7)

        animated.close()
        overlay.close()

    def test_text_reveal_is_staged_before_the_full_overlay_is_visible(self):
        base_mask = np.ones((100, 20), dtype=np.float32)
        early = _staged_text_reveal_mask_frame(base_mask, time=0.08)
        middle = _staged_text_reveal_mask_frame(base_mask, time=0.62)
        finished = _staged_text_reveal_mask_frame(base_mask, time=2.0)

        self.assertGreater(float(early[10, 0]), float(early[70, 0]))
        self.assertGreater(float(middle[35, 0]), float(middle[70, 0]))
        np.testing.assert_allclose(finished, base_mask)

    def test_provider_aliases_are_normalized_without_fabricating_a_quality_score(self):
        package = _normalize_ai_package({
            "title": "A useful title",
            "point1": "First point",
            "step_2": "Second point",
            "step3": "Third point",
            "description": "A concise caption",
            "tags": ["#one", "#two"],
            "score": 120,
        })

        self.assertEqual(package["headline"], "A useful title")
        self.assertEqual(package["point_2"], "Second point")
        self.assertEqual(package["hashtags"], "#one #two")
        self.assertIsNone(package["score"])


if __name__ == "__main__":
    unittest.main()


class ProgressReportingTest(unittest.TestCase):
    def test_progress_file_contains_stage_and_percentage(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "progress.json"
            engine._report_progress(str(path), "encoding", 82)

            self.assertEqual(
                json.loads(path.read_text(encoding="utf-8")),
                {"stage": "encoding", "percent": 82},
            )

    def test_missing_progress_path_is_ignored(self):
        engine._report_progress(None, "rendering", 50)


class ScenePolishTest(unittest.TestCase):
    def test_short_durations_are_extended_to_readable_scene_minimums(self):
        texts = ["hook line", "one two three", "four five six", "seven eight nine"]

        durations = engine.plan_scene_durations(texts, 3)

        self.assertAlmostEqual(sum(durations), 5.0, places=3)
        self.assertTrue(all(duration >= 1.2 for duration in durations))

    def test_voiceover_planning_preserves_the_requested_duration(self):
        durations = engine.plan_scene_durations(["one two three four five six", "seven eight"], 8, voiceover=True)

        self.assertAlmostEqual(sum(durations), 8.0, places=3)
        self.assertGreater(durations[0], durations[1])

    def test_bundled_arabic_font_covers_presentation_forms(self):
        self.assertTrue(engine._font_supports_arabic(engine.get_arabic_font(48)))

    def test_scene_card_renders_with_kicker_and_progress(self):
        clip = render_reference_card(
            {"headline": "اشرب كوب ماء فور الاستيقاظ", "point_1": "", "point_2": "", "point_3": ""},
            duration=2,
            handle="@page",
            language="ar",
            include_branding=False,
            include_cta=True,
            kicker="01 / 03",
            progress=(2, 4),
        )

        self.assertEqual(clip.size, (1080, 1920))
        frame = clip.get_frame(0)
        self.assertIn(frame.shape[2], (3, 4))
        # Bottom progress segments must survive the anti-border overlay.
        self.assertGreater(int(np.asarray(frame[1651:1659, 600:880]).max()), 0)


class StoryboardTest(unittest.TestCase):
    def test_storyboard_scenes_are_normalized_with_hook_and_cta(self):
        data = {
            "headline": "A better morning",
            "hook": "Your morning decides your day.",
            "scenes": [
                {"text": "Drink water first", "caption": "Drink a full glass of water after waking up.", "emphasis": "water", "duration": 2.0},
                {"text": "Avoid the phone", "caption": "Avoid your phone for the first thirty minutes.", "emphasis": "phone", "duration": 2.2},
            ],
            "cta": "Save this for tomorrow",
            "caption": "Start tomorrow with these habits.",
            "hashtags": "#morning #habits",
        }

        normalized = engine._normalize_ai_package(data, "en")

        self.assertEqual(normalized["hook"], "Your morning decides your day.")
        self.assertEqual(normalized["cta"], "Save this for tomorrow")
        self.assertEqual(len(normalized["scenes"]), 2)
        self.assertEqual(normalized["scenes"][0]["emphasis"], "water")
        self.assertEqual(normalized["point_1"], "Drink a full glass of water after waking up.")

        specs = engine.build_scene_specs(normalized, "morning", "en")
        self.assertEqual([spec["kind"] for spec in specs], ["hook", "point", "point", "cta"])

    def test_scene_durations_honor_the_requested_length_and_minimums(self):
        specs = engine.build_scene_specs(
            {"hook": "Hook", "scenes": [{"text": "One", "caption": "One"}, {"text": "Two", "caption": "Two"}], "cta": "Save"},
            "topic",
            "en",
        )
        durations = engine.plan_scene_durations(
            [spec["caption"] or spec["text"] for spec in specs],
            8,
            hints=[spec["hint"] for spec in specs],
            minimums=[spec["min"] for spec in specs],
        )

        self.assertAlmostEqual(sum(durations), 8.0, places=3)
        self.assertGreaterEqual(min(durations), 0.9)

    def test_ducking_lowers_music_while_the_voice_is_active(self):
        import numpy as np
        from moviepy.audio.AudioClip import AudioArrayClip

        sample_rate = 22050
        music = AudioArrayClip(np.full((sample_rate * 2, 2), 0.2, dtype=np.float32), fps=sample_rate)
        voice_array = np.zeros((sample_rate * 2, 2), dtype=np.float32)
        voice_array[:sample_rate] = 0.6
        voice = AudioArrayClip(voice_array, fps=sample_rate)

        ducked = engine.duck_music_to_voice(music, voice, 2, base_gain=0.2, duck_gain=0.05)
        output = ducked.to_soundarray(fps=sample_rate)

        self.assertLess(float(np.abs(output[:sample_rate]).mean()), float(np.abs(output[sample_rate:]).mean()))

    def test_word_captions_and_srt_are_built(self):
        spec = {"kind": "point", "text": "Drink water", "caption": "Drink a full glass of water every morning.", "emphasis": ""}
        clips = engine.build_caption_clips(spec, 1.0, 3.0, (720, 1280), "en", "#fb7185", 100)

        self.assertGreater(len(clips), 3)
        self.assertGreater(clips[0].duration, 0)

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "captions.srt"
            engine.write_srt([(0, 1.5, "First line"), (1.5, 3.0, "Second line")], str(path))
            content = path.read_text(encoding="utf-8")

        self.assertIn("00:00:00,000 --> 00:00:01,500", content)
        self.assertIn("Second line", content)

    def test_all_templates_and_the_end_card_render(self):
        base = {"headline": "Three simple steps", "emphasis": "", "point_1": "", "point_2": "", "point_3": ""}
        for template in ("classic", "clean_news", "cinematic", "quote_card"):
            clip = render_reference_card(base, size=(720, 1280), duration=1, template=template, handle="@page", language="en", progress=(1, 3), kicker="01 / 02")
            self.assertEqual(clip.size, (720, 1280))

        end_card = engine.render_end_card({"headline": "Save this for later"}, size=(720, 1280), duration=1, handle="@page", language="en", progress=(3, 3))
        self.assertEqual(end_card.size, (720, 1280))


class PreviewImageTest(unittest.TestCase):
    def test_preview_image_uses_the_requested_resolution(self):
        from types import SimpleNamespace
        from PIL import Image

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "preview.png"
            args = SimpleNamespace(
                language="en",
                template="quote_card",
                handle="@page",
                accent_color="#fb7185",
                font_scale=100,
                quality=720,
                aspect_ratio="9:16",
                anti_border=True,
                custom_headline="Live preview",
                custom_points=["One clear idea"],
                topic="",
                preview_image=str(path),
            )

            engine.build_preview_image(args)

            with Image.open(path) as image:
                self.assertEqual(image.size, (720, 1280))
