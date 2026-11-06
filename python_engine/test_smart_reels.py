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

    def test_provider_aliases_are_normalized_and_score_is_bounded(self):
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
        self.assertEqual(package["score"], 100)


if __name__ == "__main__":
    unittest.main()
