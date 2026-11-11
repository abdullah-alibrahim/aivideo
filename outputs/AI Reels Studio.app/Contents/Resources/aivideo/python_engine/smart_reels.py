#!/usr/bin/env python3
"""Assemble a short MP4 from source footage, text cards, and optional audio.

Arabic and English text are supported. Content is generated through Gemini;
the renderer stops with an explicit error when Gemini is unavailable.
Rendering applies the selected card template, approximate safe margins, and
background motion; it does not generate new footage or score likely reach.
"""

import os
import sys
import json
import argparse
import hashlib
import unicodedata
import tempfile
import re
import subprocess
import shutil
import wave
from pathlib import Path
import urllib.parse
import urllib.error
import urllib.request
from pathlib import Path
import numpy as np
from PIL import Image, ImageDraw, ImageFont

try:
    from moviepy import (
        VideoFileClip,
        concatenate_videoclips,
        CompositeVideoClip,
        ImageClip,
        VideoClip,
        AudioFileClip,
        ColorClip,
        CompositeAudioClip,
        concatenate_audioclips,
        afx
    )
except ImportError:
    from moviepy.editor import (
        VideoFileClip,
        concatenate_videoclips,
        CompositeVideoClip,
        ImageClip,
        VideoClip,
        AudioFileClip,
        ColorClip,
        CompositeAudioClip,
        concatenate_audioclips,
        afx
    )

from moviepy.audio.AudioClip import AudioArrayClip
from dotenv import load_dotenv

if os.getenv("PYTHON_DOTENV_DISABLED") != "1":
    load_dotenv()


def _report_progress(path, stage, percent, **metadata):
    """Write render progress atomically so the queue worker can poll it."""
    if not path:
        return
    try:
        previous = {}
        try:
            with open(path, "r", encoding="utf-8") as handle:
                previous = json.load(handle)
        except (OSError, json.JSONDecodeError):
            previous = {}

        old_failures = previous.get("failed_provider_keys", []) if isinstance(previous, dict) else []
        new_failures = metadata.get("failed_provider_keys", [])
        if old_failures or new_failures:
            failures_by_fingerprint = {}
            for failure in [*old_failures, *new_failures]:
                if isinstance(failure, dict) and isinstance(failure.get("fingerprint"), str):
                    failures_by_fingerprint[failure["fingerprint"]] = failure
            metadata["failed_provider_keys"] = list(failures_by_fingerprint.values())

        temporary = f"{path}.tmp"
        with open(temporary, "w", encoding="utf-8") as handle:
            payload = {"stage": stage, "percent": int(percent)}
            payload.update(metadata)
            json.dump(payload, handle)
        os.replace(temporary, path)
    except OSError:
        pass

def fade_audio_edges(clip, fade_in=0.18, fade_out=0.32):
    """Apply short, duration-safe fades without changing the clip's timing."""
    duration = max(0.0, float(getattr(clip, "duration", 0.0) or 0.0))
    if duration <= 0.02:
        return clip
    fade_in = min(max(0.0, fade_in), duration / 2)
    fade_out = min(max(0.0, fade_out), duration / 2)
    effects = []
    if fade_in > 0 and hasattr(afx, "AudioFadeIn"):
        effects.append(afx.AudioFadeIn(fade_in))
    if fade_out > 0 and hasattr(afx, "AudioFadeOut"):
        effects.append(afx.AudioFadeOut(fade_out))
    if effects and hasattr(clip, "with_effects"):
        return clip.with_effects(effects)
    if hasattr(clip, "fx"):
        if fade_in > 0 and hasattr(afx, "audio_fadein"):
            clip = clip.fx(afx.audio_fadein, fade_in)
        if fade_out > 0 and hasattr(afx, "audio_fadeout"):
            clip = clip.fx(afx.audio_fadeout, fade_out)
    return clip


FONT_DIRECTORY = Path(__file__).resolve().parent / "fonts"
BUNDLED_FONT = FONT_DIRECTORY / "NotoKufiArabic.ttf"
WINDOWS_FONT_DIRECTORY = Path(os.environ.get("WINDIR", r"C:\Windows")) / "Fonts"

SYSTEM_FONTS = [
    Path("/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf"),
    Path("/usr/share/fonts/truetype/noto/NotoSansArabic-Bold.ttf"),
    Path("/usr/local/share/fonts/NotoSansArabic-Regular.ttf"),
    Path("/System/Library/Fonts/GeezaPro.ttc"),
    Path("/System/Library/Fonts/SFArabic.ttf"),
    Path("/System/Library/Fonts/Supplemental/Arial Bold.ttf"),
    Path("/System/Library/Fonts/Supplemental/Arial.ttf"),
    Path("/System/Library/Fonts/Helvetica.ttc"),
    Path("/Library/Fonts/Arial.ttf"),
    WINDOWS_FONT_DIRECTORY / "NotoSansArabic-Regular.ttf",
    WINDOWS_FONT_DIRECTORY / "NotoSansArabic-Bold.ttf",
    WINDOWS_FONT_DIRECTORY / "Tahoma.ttf",
    WINDOWS_FONT_DIRECTORY / "Arial.ttf",
    WINDOWS_FONT_DIRECTORY / "segoeui.ttf",
]


def _load_font(path, size, weight=None):
    font = ImageFont.truetype(str(path), size)
    if weight is not None:
        try:
            font.set_variation_by_axes([weight])
        except Exception:
            pass
    return font


def _font_supports_arabic(font):
    """Reject fonts that have no Arabic presentation forms (they render tofu)."""
    try:
        missing = np.array(font.getmask("\U0010FFFF"))
        sample = shape_arabic_word("عادات")
        return all(
            not np.array_equal(np.array(font.getmask(character)), missing)
            for character in sample
        )
    except Exception:
        return False


def _ffmpeg_binary():
    try:
        from imageio_ffmpeg import get_ffmpeg_exe
        return get_ffmpeg_exe()
    except Exception:
        return shutil.which("ffmpeg")


def ensure_ambient_bed(duration=24.0, sample_rate=44100):
    """Create an original, royalty-free ambient pad once and reuse it as a bed."""
    path = Path(__file__).resolve().parent / "storage" / "music" / "ambient_bed.wav"
    if path.is_file() and path.stat().st_size > 4096:
        return str(path)

    path.parent.mkdir(parents=True, exist_ok=True)
    timeline = np.arange(int(duration * sample_rate), dtype=np.float32) / sample_rate
    signal = np.zeros_like(timeline)
    progression = [
        (220.00, 261.63, 329.63),
        (174.61, 220.00, 261.63),
        (130.81, 164.81, 196.00),
        (196.00, 246.94, 293.66),
    ]
    bar_duration = duration / len(progression)

    for index, chord in enumerate(progression):
        start = index * bar_duration
        local = np.clip(timeline - start, 0.0, bar_duration)
        envelope = np.clip(np.minimum(1.0, local / 0.9), 0.0, 1.0)
        envelope *= np.clip(np.minimum(1.0, (bar_duration - local) / 1.1), 0.0, 1.0)
        for frequency in chord:
            signal += 0.055 * np.sin(2 * np.pi * frequency * local + index) * envelope
            signal += 0.018 * np.sin(2 * np.pi * frequency * 2.003 * local + index * 0.7) * envelope

    signal *= 0.9 + 0.1 * np.sin(2 * np.pi * 0.08 * timeline)
    peak = float(np.max(np.abs(signal)) or 1.0)
    signal = signal / peak * 0.22

    fade = int(1.0 * sample_rate)
    signal[:fade] *= np.linspace(0.0, 1.0, fade)
    signal[-fade:] *= np.linspace(1.0, 0.0, fade)

    stereo = np.stack([signal, signal], axis=1)
    pcm = (np.clip(stereo, -1.0, 1.0) * 32767).astype(np.int16)
    with wave.open(str(path), "wb") as handle:
        handle.setnchannels(2)
        handle.setsampwidth(2)
        handle.setframerate(sample_rate)
        handle.writeframes(pcm.tobytes())

    return str(path)


def duck_music_to_voice(music_clip, voice_clip, duration, base_gain=0.16, duck_gain=0.07, sample_rate=22050):
    """Lower the music under narration using a real amplitude sidechain."""
    try:
        music = music_clip.to_soundarray(fps=sample_rate)
        voice = voice_clip.to_soundarray(fps=sample_rate)
        if music.ndim == 1:
            music = music[:, None]
        if voice.ndim == 1:
            voice = voice[:, None]

        envelope = np.abs(voice).mean(axis=1)
        window = max(1, int(sample_rate * 0.18))
        envelope = np.convolve(envelope, np.ones(window) / window, mode="same")
        envelope = envelope / (float(envelope.max()) or 1.0)
        gains = duck_gain + (base_gain - duck_gain) * (1.0 - envelope)

        if len(gains) < len(music):
            gains = np.concatenate([gains, np.full(len(music) - len(gains), base_gain)])

        output = music * gains[:len(music), None]
        clip = AudioArrayClip(output, fps=sample_rate)
        return clip.with_duration(duration) if hasattr(clip, "with_duration") else clip.set_duration(duration)
    except Exception as exc:
        print(f"[Audio Warning] Sidechain ducking failed: {exc}")
        return music_clip.with_volume_scaled(duck_gain) if hasattr(music_clip, "with_volume_scaled") else music_clip.volumex(duck_gain)


def normalize_loudness(path, target=-14.0):
    """Re-encode audio to a platform-friendly loudness without touching the video."""
    ffmpeg = _ffmpeg_binary()
    if not ffmpeg:
        return False

    temporary = f"{path}.loudnorm.mp4"
    try:
        subprocess.run(
            [
                ffmpeg, "-y", "-i", path,
                "-c:v", "copy",
                "-af", f"loudnorm=I={target}:TP=-1.5:LRA=11",
                "-c:a", "aac", "-b:a", "192k", "-ar", "48000",
                "-movflags", "+faststart",
                temporary,
            ],
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=180,
        )
        os.replace(temporary, path)
        return True
    except Exception as exc:
        print(f"[Audio Warning] Loudness normalization failed: {exc}")
        if os.path.exists(temporary):
            os.unlink(temporary)
        return False


def write_thumbnail(video_path, image_path, duration):
    ffmpeg = _ffmpeg_binary()
    if not ffmpeg:
        return False
    try:
        timestamp = min(max(float(duration) * 0.35, 0.8), max(0.1, float(duration) - 0.1))
        os.makedirs(os.path.dirname(os.path.abspath(image_path)), exist_ok=True)
        subprocess.run(
            [ffmpeg, "-y", "-ss", f"{timestamp:.3f}", "-i", video_path, "-frames:v", "1", "-q:v", "3", image_path],
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=60,
        )
        return os.path.isfile(image_path) and os.path.getsize(image_path) > 512
    except Exception as exc:
        print(f"[Thumbnail Warning] Could not write thumbnail: {exc}")
        return False


def get_font(size=46, bold=True):
    if BUNDLED_FONT.exists():
        try:
            return _load_font(BUNDLED_FONT, size, 800 if bold else 500)
        except Exception:
            pass

    for font_path in SYSTEM_FONTS:
        if os.path.exists(font_path):
            try:
                return ImageFont.truetype(font_path, size)
            except Exception:
                continue

    return ImageFont.load_default()


def get_arabic_font(size=46):
    configured = os.getenv("AIVIDEO_ARABIC_FONT", "").strip()
    if configured and os.path.exists(configured):
        try:
            font = ImageFont.truetype(configured, size)
            if _font_supports_arabic(font):
                return font
            print("[Font Warning] AIVIDEO_ARABIC_FONT lacks Arabic glyphs; using the bundled font.")
        except Exception as exc:
            print(f"[Font Warning] Could not load AIVIDEO_ARABIC_FONT: {exc}")

    if BUNDLED_FONT.exists():
        try:
            return _load_font(BUNDLED_FONT, size, 800)
        except Exception:
            pass

    for font_path in SYSTEM_FONTS:
        if os.path.exists(font_path):
            try:
                font = ImageFont.truetype(font_path, size)
                if _font_supports_arabic(font):
                    return font
            except Exception:
                continue

    raise RuntimeError(
        "No Arabic-capable font found. Add a licensed font at python_engine/fonts/NotoKufiArabic.ttf "
        "or set AIVIDEO_ARABIC_FONT to a font that includes Arabic presentation forms."
    )


def get_number_font(size=46, bold=True):
    return get_font(size, bold=bold)


def _build_arabic_presentation_forms():
    forms = {}
    for codepoint in list(range(0xFB50, 0xFE00)) + list(range(0xFE70, 0xFF00)):
        character = chr(codepoint)
        parts = unicodedata.decomposition(character).split()
        if len(parts) != 2 or not parts[0].startswith('<'):
            continue
        tag = parts[0][1:-1]
        if tag not in {'isolated', 'final', 'initial', 'medial'}:
            continue
        try:
            base = chr(int(parts[1], 16))
        except ValueError:
            continue
        forms.setdefault(base, {})[tag] = character
    return forms


ARABIC_PRESENTATION_FORMS = _build_arabic_presentation_forms()


def contains_arabic(text):
    return any("\u0600" <= char <= "\u06ff" or "\u0750" <= char <= "\u077f" for char in str(text or ""))


def shape_arabic_word(word):
    """Shape and visually order one Arabic token for Pillow builds without RAQM."""
    characters = list(str(word))
    if not any('\u0600' <= char <= '\u06ff' for char in characters):
        return ''.join(characters)

    output = []
    for index, char in enumerate(characters):
        forms = ARABIC_PRESENTATION_FORMS.get(char)
        if not forms:
            output.append(char)
            continue

        previous_index = index - 1
        while previous_index >= 0 and unicodedata.combining(characters[previous_index]):
            previous_index -= 1
        next_index = index + 1
        while next_index < len(characters) and unicodedata.combining(characters[next_index]):
            next_index += 1

        previous_forms = ARABIC_PRESENTATION_FORMS.get(characters[previous_index], {}) if previous_index >= 0 else {}
        next_forms = ARABIC_PRESENTATION_FORMS.get(characters[next_index], {}) if next_index < len(characters) else {}
        joins_previous = previous_index >= 0 and ('initial' in previous_forms or 'medial' in previous_forms) and ('final' in forms or 'medial' in forms)
        joins_next = next_index < len(characters) and ('initial' in forms or 'medial' in forms) and ('final' in next_forms or 'medial' in next_forms)

        form_name = 'medial' if joins_previous and joins_next else ('final' if joins_previous else ('initial' if joins_next else 'isolated'))
        output.append(forms.get(form_name, forms.get('isolated', char)))

    return ''.join(reversed(output))

def _clean_json(raw):
    raw = (raw or "").strip()
    if raw.startswith("```json"):
        raw = raw[7:]
    if raw.startswith("```"):
        raw = raw[3:]
    if raw.endswith("```"):
        raw = raw[:-3]
    raw = raw.strip()
    start = raw.find("{")
    end = raw.rfind("}")
    if start >= 0 and end > start:
        return raw[start:end + 1]
    return raw


def _ai_prompt(topic, niche, goal, language, user_prompt="", style="engaging", format_type="full_idea", target_country="Global"):
    lang_label = "English" if language == "en" else "Arabic"
    return f"""
You are a careful short-form video script editor. Do not promise virality or invent facts.
Topic: "{topic}"
User's full brief: "{user_prompt or topic}"
Niche: "{niche}"
Goal: "{goal}"
Target audience market: "{target_country}"
Style: "{style}"
Format: "{format_type}"
Language: {lang_label}

Generate a JSON object with:
1. "headline": A clear, accurate title (max 8 words).
2. "hook": The spoken opening line (max 10 words). It must make sense within the first 0.8 seconds; no "in this video" or slow introductions.
3. "scenes": 3-4 objects, each:
   {{
     "text": short on-card phrase (max 7 words),
     "caption": the full spoken sentence (max 18 words),
     "emphasis": one word that appears in "text" and deserves the accent color,
     "duration": suggested seconds between 1.2 and 3.5
   }}
   Each scene must teach one concrete idea. Do not repeat the hook.
4. "cta": one short imperative call to action (max 5 words, e.g. "Save this for later").
5. "caption": A relevant post caption; do not invent a personal story or unsupported outcome.
6. "hashtags": 4-7 relevant general hashtags; do not claim they are trending.

Keep every sentence accurate and specific. Output ONLY valid JSON.
"""


def _safe_provider_failure(exc):
    """Return a safe diagnostic without URLs, request bodies, or credentials."""
    status = getattr(exc, "code", None)
    if isinstance(status, int):
        return status, f"HTTP {status}"
    if isinstance(exc, (TimeoutError, ConnectionError)) or getattr(exc, "reason", None) is not None:
        return None, "network unavailable"
    if isinstance(exc, (json.JSONDecodeError, ValueError)):
        return None, "invalid provider response"
    return None, "provider error"


def _try_gemini_key(key, topic, niche, goal, language, user_prompt="", style="engaging", format_type="full_idea", target_country="Global"):
    import urllib.request
    prompt = _ai_prompt(topic, niche, goal, language, user_prompt, style, format_type, target_country)
    last_exc = None
    for model in ("gemini-3-flash-preview", "gemini-3.5-flash", "gemini-2.5-flash"):
        try:
            url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={key}"
            payload = {
                "contents": [{"parts": [{"text": prompt}]}],
                "generationConfig": {
                    "temperature": 0.8,
                    "maxOutputTokens": 1800,
                    "responseMimeType": "application/json",
                }
            }
            req = urllib.request.Request(
                url,
                data=json.dumps(payload).encode("utf-8"),
                headers={"Content-Type": "application/json"},
                method="POST",
            )
            with urllib.request.urlopen(req, timeout=45) as response:
                result = json.loads(response.read().decode("utf-8"))
            candidates = result.get("candidates") or []
            if not candidates:
                raise ValueError("Gemini returned no candidates")
            parts = candidates[0].get("content", {}).get("parts", [])
            raw = "".join(p.get("text", "") for p in parts)
            data = json.loads(_clean_json(raw))
            if data.get("headline"):
                data["ai_model"] = model
                print(f"[AI] Gemini succeeded with {model}")
                return data
        except Exception as e:
            last_exc = e
            _, safe_reason = _safe_provider_failure(e)
            print(f"[AI Warning] Gemini {model} failed: {safe_reason}")
    if last_exc:
        raise last_exc
    return None


def _environment_keys(variable):
    try:
        value = json.loads(os.getenv(variable, "[]"))
    except (json.JSONDecodeError, TypeError):
        return []

    if not isinstance(value, list):
        return []

    return [key.strip() for key in value if isinstance(key, str) and key.strip() and not key.startswith("your-")]


def _normalize_storyboard_scene(item):
    if not isinstance(item, dict):
        return None

    text = str(item.get("text") or item.get("line") or item.get("headline") or "").strip()
    if not text:
        return None

    kind = str(item.get("kind") or item.get("type") or "point").strip().lower()
    if kind in {"hook", "intro", "cta", "outro"}:
        return None

    caption = str(item.get("caption") or item.get("subtitle") or text).strip()
    emphasis = str(item.get("emphasis") or item.get("highlight") or "").strip()
    try:
        duration = float(item.get("duration") or 0)
    except (TypeError, ValueError):
        duration = 0.0
    if duration <= 0 or duration > 15:
        duration = None

    return {
        "text": text,
        "caption": caption,
        "emphasis": emphasis,
        "duration": duration,
        "kind": "point",
    }


def _normalize_ai_package(data, language="en"):
    """Normalize provider responses into the renderer's stable data contract."""
    if not isinstance(data, dict):
        return None

    aliases = {
        "headline": ("headline", "title", "hook"),
        "point_1": ("point_1", "point1", "step_1", "step1"),
        "point_2": ("point_2", "point2", "step_2", "step2"),
        "point_3": ("point_3", "point3", "step_3", "step3"),
        "caption": ("caption", "description", "post_caption"),
        "hashtags": ("hashtags", "hash_tags", "tags"),
    }
    normalized = {}
    for target, candidates in aliases.items():
        value = next((data.get(key) for key in candidates if data.get(key) is not None), "")
        if isinstance(value, list):
            value = " ".join(str(item).strip() for item in value if str(item).strip())
        normalized[target] = str(value).strip()

    scenes = []
    raw_scenes = data.get("scenes")
    if isinstance(raw_scenes, list):
        scenes = [scene for scene in (_normalize_storyboard_scene(item) for item in raw_scenes) if scene]

    if not scenes:
        fallback = [normalized["point_1"], normalized["point_2"], normalized["point_3"]]
        scenes = [
            {"text": point, "caption": point, "emphasis": "", "duration": None, "kind": "point"}
            for point in fallback if point
        ]

    if not normalized["headline"] and scenes:
        normalized["headline"] = scenes[0]["text"]

    if not normalized["headline"]:
        return None

    hook = str(data.get("hook") or data.get("opening") or normalized["headline"]).strip()
    cta = str(data.get("cta") or data.get("call_to_action") or "").strip()
    if not cta:
        cta = "Save this for later" if language == "en" else "احفظ هذا وارجع له لاحقاً"

    for index, scene in enumerate(scenes[:3], start=1):
        normalized[f"point_{index}"] = scene["caption"] or scene["text"]

    normalized["hook"] = hook
    normalized["cta"] = cta
    normalized["scenes"] = scenes

    # This studio does not measure audience performance or content quality.
    normalized["score"] = None

    return normalized


def generate_ai_package(topic, niche="productivity", goal="followers", language="en", style="engaging", gemini_api_keys=None, user_prompt="", format_type="full_idea", target_country="Global", progress_file=None):
    """
    Generate complete bundle. Tries all supplied/known keys and models until one
    succeeds; otherwise fails without generating local content.
    """
    gemini_keys = [
        key for key in [*(gemini_api_keys or []), *_environment_keys("AIVIDEO_GEMINI_KEYS_JSON")]
        if key and not str(key).startswith("your-")
    ]

    env_gemini = os.getenv("GEMINI_API_KEY") or os.getenv("GOOGLE_API_KEY")
    if env_gemini and not env_gemini.startswith("your-") and env_gemini not in gemini_keys:
        gemini_keys.append(env_gemini)

    failed_provider_keys = []
    last_failure_message = "provider error"
    for key in gemini_keys:
        try:
            data = _try_gemini_key(key, topic, niche, goal, language, user_prompt, style, format_type, target_country)
            if data:
                normalized = _normalize_ai_package(data, language)
                if normalized:
                    normalized["content_source"] = "gemini"
                    normalized["ai_model"] = data.get("ai_model")
                    normalized["provider_key_fingerprint"] = hashlib.sha256(key.encode("utf-8")).hexdigest()
                    return normalized
        except Exception as e:
            status, safe_reason = _safe_provider_failure(e)
            fingerprint = hashlib.sha256(key.encode("utf-8")).hexdigest()
            last_failure_message = safe_reason
            failed_provider_keys.append({"fingerprint": fingerprint, "status": status, "reason": safe_reason})
            print(f"[AI Warning] Gemini key failed: {safe_reason}")
            _report_progress(progress_file, "writing_script", 30, failed_provider_keys=failed_provider_keys)

    if not gemini_keys:
        raise RuntimeError("Gemini API key is required. Add an active Gemini key before generating content.")
    raise RuntimeError(f"Gemini content generation failed for all configured keys ({last_failure_message}). No local fallback is available.")

def wrap_text(text, font, max_width, draw):
    words = text.split()
    lines = []
    curr = []
    for w in words:
        test = ' '.join(curr + [w])
        bbox = draw.textbbox((0, 0), test, font=font)
        if (bbox[2] - bbox[0]) <= max_width:
            curr.append(w)
        else:
            if curr: lines.append(' '.join(curr))
            curr = [w]
    if curr: lines.append(' '.join(curr))
    return lines

def hex_to_rgb(hex_color, fallback=(251, 113, 133)):
    if isinstance(hex_color, (tuple, list)):
        return tuple(hex_color)
    if not isinstance(hex_color, str):
        return fallback
    h = hex_color.strip().lstrip('#')
    if len(h) == 3:
        h = ''.join(c * 2 for c in h)
    if len(h) != 6:
        return fallback
    try:
        return (int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16))
    except ValueError:
        return fallback


def _normalize_word(word):
    return re.sub(r"[^\w\u0600-\u06ff]", "", str(word or "")).casefold()


def tokenize_styled(text, accent_rgb, default_color=(255, 255, 255), emphasis=None):
    tokens = []
    parts = str(text or '').split('*')
    explicit_highlight = any(i % 2 == 1 and part.strip() for i, part in enumerate(parts))
    for i, part in enumerate(parts):
        color = accent_rgb if (i % 2 == 1) else default_color
        for word in part.split():
            tokens.append([word, color])

    emphasis_target = _normalize_word(emphasis)
    if emphasis_target:
        for token in tokens:
            if _normalize_word(token[0]) == emphasis_target:
                token[1] = accent_rgb
                return tokens

    if tokens and str(tokens[0][0])[:1].isdigit():
        tokens[0][1] = (249, 115, 22)
    if tokens and not explicit_highlight:
        first_content_index = 1 if str(tokens[0][0]).rstrip('.)،:-').isdigit() else 0
        if first_content_index < len(tokens):
            tokens[first_content_index][1] = accent_rgb
    return tokens


def _format_handle(handle):
    value = str(handle or "").strip()
    if value and not value.startswith("@") and not value.startswith("http"):
        value = "@" + value
    return value


def _display_word(word, language):
    return shape_arabic_word(word) if contains_arabic(word) else word


def narration_text_from_package(package):
    parts = [str(package.get("hook") or package.get("headline", "")).strip()]
    scenes = package.get("scenes") or []
    for scene in scenes:
        value = str(scene.get("caption") or scene.get("text") or "").strip()
        if value:
            parts.append(value)
    if not scenes:
        for index in range(1, 4):
            point = str(package.get(f"point_{index}", "")).strip()
            point = re.sub(r"^\s*\d{1,2}[.)،:-]?\s*", "", point)
            if point:
                parts.append(point)
    cta = str(package.get("cta") or "").strip()
    if cta:
        parts.append(cta)
    return " ".join(part for part in parts if part)


def generate_elevenlabs_voiceover(text, api_key, voice_id, output_path, tone="neutral"):
    api_keys = api_key if isinstance(api_key, (list, tuple)) else [api_key]
    api_keys = [str(key).strip() for key in api_keys if key and str(key).strip()]
    if not api_keys or not voice_id:
        raise ValueError("ElevenLabs narration needs an active server key and a configured voice ID.")

    tone_settings = {
        "neutral": {"stability": 0.52, "style": 0.14},
        "calm": {"stability": 0.62, "style": 0.06},
        "warm": {"stability": 0.5, "style": 0.22},
        "energetic": {"stability": 0.38, "style": 0.36},
    }
    settings = tone_settings.get(tone, tone_settings["neutral"])
    payload = {
        "text": str(text).strip(),
        "model_id": "eleven_multilingual_v2",
        "voice_settings": {
            "stability": settings["stability"],
            "similarity_boost": 0.78,
            "style": settings["style"],
            "use_speaker_boost": True,
        },
    }
    url = "https://api.elevenlabs.io/v1/text-to-speech/" + urllib.parse.quote(str(voice_id), safe="") + "?output_format=mp3_44100_128"
    audio_data = b""
    last_status = None
    retryable_statuses = {401, 402, 403, 429, 500, 502, 503, 504}
    for key in api_keys:
        request = urllib.request.Request(
            url,
            data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
            headers={
                "Content-Type": "application/json",
                "Accept": "audio/mpeg",
                "xi-api-key": key,
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                audio_data = response.read()
        except urllib.error.HTTPError as error:
            last_status = error.code
            if error.code not in retryable_statuses:
                raise RuntimeError(f"ElevenLabs narration request failed with status {error.code}.") from None
            continue
        except urllib.error.URLError:
            raise RuntimeError("ElevenLabs narration service could not be reached.") from None
        if audio_data:
            break

    if not audio_data:
        if last_status is not None:
            raise RuntimeError(f"ElevenLabs narration request failed with status {last_status} for all active keys.")
        raise RuntimeError("ElevenLabs returned an empty narration file.")
    with open(output_path, "wb") as audio_file:
        audio_file.write(audio_data)
    return output_path


def is_number_token(word):
    return str(word).strip().rstrip(".),،:-").isdigit()


def _font_for_word(word, font, number_font=None, arabic_font=None):
    if contains_arabic(word) and arabic_font is not None:
        return arabic_font
    return number_font if number_font is not None and is_number_token(word) else font


def wrap_tokens(tokens, font, max_width, draw, language="en", number_font=None, arabic_font=None):
    lines = []
    current = []
    current_width = 0
    space_w = int(draw.textlength(' ', font=font)) or 1
    for word, color in tokens:
        word_font = _font_for_word(word, font, number_font, arabic_font)
        word_w = int(draw.textlength(_display_word(word, language), font=word_font))
        if current and current_width + space_w + word_w > max_width:
            lines.append(current)
            current = [[word, color]]
            current_width = word_w
        else:
            if current:
                current_width += space_w + word_w
            else:
                current_width = word_w
            current.append([word, color])
    if current:
        lines.append(current)
    return lines


def rich_line_width(line, font, draw, language="en", number_font=None, arabic_font=None):
    space_w = int(draw.textlength(' ', font=font)) or 1
    if not line:
        return 0
    return sum(
        int(draw.textlength(_display_word(word, language), font=_font_for_word(word, font, number_font, arabic_font)))
        for word, _ in line
    ) + (len(line) - 1) * space_w


def _vertical_reveal_mask_frame(base_mask, time, start_y, end_y, delay, reveal_duration, feather):
    """Return a time-deterministic top-to-bottom reveal mask for the text overlay."""
    height, _ = base_mask.shape
    progress = np.clip((float(time) - delay) / max(reveal_duration, 1e-6), 0.0, 1.0)
    eased_progress = 1.0 - (1.0 - progress) ** 3
    reveal_edge = start_y + (end_y - start_y) * eased_progress
    rows = np.arange(height, dtype=np.float32)
    reveal = np.clip((reveal_edge + feather / 2.0 - rows) / max(feather, 1.0), 0.0, 1.0)
    return base_mask * reveal[:, None]


def _staged_text_reveal_mask_frame(base_mask, time, feather=18):
    """Reveal overlay bands in reading order for a more deliberate text entrance."""
    height, _ = base_mask.shape
    rows = np.arange(height, dtype=np.float32)
    normalized = rows / max(float(height), 1.0)
    # Branding, headline, point 1, and points 2-3 get separate entrance beats.
    stages = np.select(
        [normalized < 0.26, normalized < 0.43, normalized < 0.56, normalized < 0.73],
        [0.00, 0.16, 0.46, 0.78],
        default=1.05,
    )
    local_progress = np.clip((float(time) - stages) / 0.42, 0.0, 1.0)
    eased = local_progress * local_progress * (3.0 - 2.0 * local_progress)
    reveal = eased[:, None]
    # A small vertical feather keeps card edges from popping harshly between beats.
    if feather > 0:
        reveal = np.maximum(reveal, np.roll(reveal, 1, axis=0) * 0.22)
    return base_mask * reveal


def _horizontal_reveal_mask_frame(base_mask, time, direction="right", reveal_duration=0.48, feather=20):
    """Reveal an overlay from one horizontal edge while preserving its alpha."""
    _, width = base_mask.shape
    progress = np.clip(float(time) / max(float(reveal_duration), 1e-6), 0.0, 1.0)
    eased = progress * progress * (3.0 - 2.0 * progress)
    columns = np.arange(width, dtype=np.float32)
    edge = width * (1.0 - eased) if direction == "right" else width * eased
    if direction == "right":
        reveal = np.clip((columns - edge + feather) / max(float(feather), 1.0), 0.0, 1.0)
    else:
        reveal = np.clip((edge + feather - columns) / max(float(feather), 1.0), 0.0, 1.0)
    return base_mask * reveal[None, :]


def render_reference_card(package, size=(1080, 1920), duration=6, template="classic", handle="", anti_border=True, language="en", accent_color="#fb7185", font_scale=100, include_branding=True, include_cta=True, kicker=None, progress=None):
    """
    Renders a text-card overlay with the selected visual template:
    - Floating round profile badge with logo icon
    - Floating round profile badge with logo icon
    - optional user page handle
    - Bold headline card
    - Numbered points with colored key highlight words
    - Safe zones / anti-border margins (شريط شفاف لتجنب حدود المنصات)
    """
    accent_rgb = hex_to_rgb(accent_color)
    width, height = size
    img = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    scale = max(0.5, min(1.3, (float(font_scale) / 100.0) * min(width / 1080.0, height / 1920.0)))
    handle = _format_handle(handle)
    font_handle = get_font(round(26 * scale), bold=False)
    font_head = get_font(round(52 * scale), bold=True)
    font_number_head = get_number_font(round(52 * scale), bold=True)
    has_arabic_content = any(contains_arabic(package.get(key, '')) for key in ('headline', 'point_1', 'point_2', 'point_3', 'caption', 'hashtags', 'handle'))
    font_arabic_head = get_arabic_font(round(52 * scale)) if has_arabic_content else None
    font_point = get_font(round(38 * scale), bold=True)
    font_number_point = get_number_font(round(38 * scale), bold=True)
    font_arabic_point = get_arabic_font(round(38 * scale)) if has_arabic_content else None
    font_footer = get_font(round(24 * scale), bold=True)
    font_arabic_footer = get_arabic_font(round(24 * scale)) if has_arabic_content else None
    font_kicker = get_font(round(26 * scale), bold=True)
    font_arabic_kicker = get_arabic_font(round(26 * scale)) if has_arabic_content else None
    head_line_height = round(68 * scale)
    point_line_height = round(54 * scale)

    # Safe boundaries margin
    margin_x = int(width * 0.08)
    card_w = width - (margin_x * 2)

    # Branding is shown on the opening beat; subsequent beats focus on one idea.
    if include_branding:
        if str(handle).strip():
            start_y = int(height * 0.22)
            logo_size = 64
            logo_x = (width - logo_size) // 2
            logo_y = start_y
            draw.ellipse([logo_x, logo_y, logo_x + logo_size, logo_y + logo_size], fill=(249, 115, 66))
            draw.ellipse([logo_x + 8, logo_y + 8, logo_x + logo_size - 8, logo_y + logo_size - 8], fill=(30, 41, 59))
            display_handle = _display_word(handle, language)
            handle_font = font_arabic_footer if contains_arabic(handle) else font_handle
            bbox_h = draw.textbbox((0, 0), display_handle, font=handle_font)
            hx = (width - (bbox_h[2] - bbox_h[0])) // 2
            draw.text((hx, logo_y + logo_size + 12), display_handle, font=handle_font, fill=(203, 213, 225, 220))
            cur_y = logo_y + logo_size + 54
        else:
            cur_y = int(height * 0.3)
    else:
        cur_y = int(height * 0.34)

    # One persistent, low-key handle keeps every scene on-brand.
    if not include_branding and handle:
        display_handle = _display_word(handle, language)
        handle_font = font_arabic_footer if contains_arabic(handle) else font_handle
        bbox_handle = draw.textbbox((0, 0), display_handle, font=handle_font)
        hx = (width - (bbox_handle[2] - bbox_handle[0])) // 2
        draw.text((hx, int(height * 0.18)), display_handle, font=handle_font, fill=(203, 213, 225, 165))

    # Determine headline text color per template
    if template == "clean_news":
        text_fill = (15, 23, 42)
    else:
        text_fill = (255, 255, 255)

    # 2. Main Headline Card
    headline_text = str(package.get("headline", ""))
    emphasis = str(package.get("emphasis", "")).strip()
    headline_tokens = tokenize_styled(headline_text, accent_rgb, text_fill, emphasis)

    # Scene text must stay readable: shrink until it fits in at most three lines.
    if progress is not None and headline_text.strip():
        head_size = round(52 * scale)
        minimum_head_size = round(38 * scale)
        while head_size > minimum_head_size:
            trial_head = get_font(head_size, bold=True)
            trial_number = get_number_font(head_size, bold=True)
            trial_arabic = get_arabic_font(head_size) if has_arabic_content else None
            trial_lines = wrap_tokens(headline_tokens, trial_head, card_w - 70, draw, language, trial_number, trial_arabic)
            if len(trial_lines) <= 3:
                break
            head_size -= 4

        font_head = get_font(head_size, bold=True)
        font_number_head = get_number_font(head_size, bold=True)
        font_arabic_head = get_arabic_font(head_size) if has_arabic_content else None
        head_line_height = round(head_size * 1.32)

    headline_lines = wrap_tokens(headline_tokens, font_head, card_w - 70, draw, language, font_number_head, font_arabic_head)
    head_h = (len(headline_lines) * head_line_height) + round(40 * scale)

    if progress is not None:
        # Single-idea scenes read better when the card sits near the optical center.
        cur_y = max(int(height * 0.24), int((height - head_h) * 0.40))

    # Template styling
    if template == "clean_news":
        # Editorial white card with a hard accent rule.
        draw.rounded_rectangle([margin_x, cur_y, width - margin_x, cur_y + head_h], radius=8, fill=(248, 250, 252, 252), outline=(15, 23, 42, 30), width=2)
        draw.rectangle([margin_x, cur_y, width - margin_x, cur_y + max(4, round(6 * scale))], fill=accent_rgb + (255,))
        text_fill = (15, 23, 42)
    elif template == "cinematic":
        # Full-width lower third with thin cinematic rules.
        draw.rectangle([0, cur_y, width, cur_y + head_h], fill=(5, 8, 14, 224))
        line_width = max(2, round(3 * scale))
        draw.rectangle([0, cur_y, width, cur_y + line_width], fill=accent_rgb + (230,))
        draw.rectangle([0, cur_y + head_h - line_width, width, cur_y + head_h], fill=(255, 255, 255, 55))
        text_fill = (255, 255, 255)
    elif template == "quote_card":
        # No chrome: a quiet scrim, a quote mark, and an accent underline.
        draw.rounded_rectangle([margin_x, cur_y, width - margin_x, cur_y + head_h], radius=4, fill=(9, 13, 22, 170))
        quote_font = get_arabic_font(round(110 * scale)) if language == "ar" else get_font(round(110 * scale), bold=True)
        draw.text((margin_x + round(12 * scale), cur_y - round(78 * scale)), "“", font=quote_font, fill=accent_rgb + (210,))
        draw.rectangle([margin_x + round(24 * scale), cur_y + head_h - max(3, round(4 * scale)), width - margin_x - round(24 * scale), cur_y + head_h], fill=accent_rgb + (220,))
        text_fill = (255, 255, 255)
    elif template == "bold_stripe":
        # Poster-style headline: deep scrim, thick accent stripe, oversized type.
        draw.rectangle([0, cur_y, width, cur_y + head_h], fill=(8, 11, 20, 214))
        stripe = max(10, round(16 * scale))
        draw.rectangle([0, cur_y, stripe, cur_y + head_h], fill=accent_rgb + (255,))
        echo = max(4, round(5 * scale))
        draw.rectangle([stripe + echo, cur_y, stripe + echo * 3, cur_y + head_h], fill=accent_rgb + (90,))
        text_fill = (255, 255, 255)
    else:  # Classic glass card.
        draw.rounded_rectangle([margin_x, cur_y, width - margin_x, cur_y + head_h], radius=24, fill=(15, 23, 42, 230), outline=(255, 255, 255, 40), width=2)
        text_fill = (255, 255, 255)

    if head_h > 72:
        bar_x = width - margin_x - 12 if language == "ar" else margin_x + 6
        draw.rounded_rectangle([bar_x, cur_y + 18, bar_x + 6, cur_y + head_h - 18], radius=3, fill=accent_rgb + (230,))

    if kicker:
        kicker_text = _display_word(str(kicker), language)
        kicker_font = font_arabic_kicker if contains_arabic(str(kicker)) else font_kicker
        kicker_box = draw.textbbox((0, 0), kicker_text, font=kicker_font)
        kicker_w = (kicker_box[2] - kicker_box[0]) + 46
        kicker_h = 46
        kicker_x = (width - kicker_w) // 2
        kicker_y = cur_y - kicker_h - 16
        draw.rounded_rectangle([kicker_x, kicker_y, kicker_x + kicker_w, kicker_y + kicker_h], radius=23, fill=(9, 13, 22, 205), outline=accent_rgb + (210,), width=2)
        draw.text((kicker_x + 23, kicker_y + 9), kicker_text, font=kicker_font, fill=accent_rgb + (255,))

    for i, line in enumerate(headline_lines):
        lw = rich_line_width(line, font_head, draw, language, font_number_head, font_arabic_head)
        lx = (width - lw) // 2
        ly = cur_y + round(20 * scale) + (i * head_line_height)
        space_w = int(draw.textlength(' ', font=font_head)) or 1
        if any(contains_arabic(word) for word, _ in line):
            right_x = lx + lw
            for word, color in line:
                display_word = _display_word(word, language)
                word_font = _font_for_word(word, font_head, font_number_head, font_arabic_head)
                word_w = int(draw.textlength(display_word, font=word_font))
                right_x -= word_w
                draw.text((right_x, ly), display_word, font=word_font, fill=color)
                right_x -= space_w
        else:
            for word, color in line:
                display_word = _display_word(word, language)
                word_font = _font_for_word(word, font_head, font_number_head, font_arabic_head)
                draw.text((lx, ly), display_word, font=word_font, fill=color)
                lx += int(draw.textlength(display_word, font=word_font)) + space_w

    cur_y += head_h + 30

    # 3. Points Cards (1, 2, 3)
    points = [package.get("point_1", ""), package.get("point_2", ""), package.get("point_3", "")]
    points = [p for p in points if p]

    for idx, pt in enumerate(points):
        point_text = str(pt).strip()
        first_word = point_text.split(maxsplit=1)[0] if point_text else ""
        if point_text and not is_number_token(first_word):
            point_text = f"{idx + 1}. {point_text}"
        p_tokens = tokenize_styled(point_text, accent_rgb, (255, 255, 255), emphasis)
        p_lines = wrap_tokens(p_tokens, font_point, card_w - 70, draw, language, font_number_point, font_arabic_point)
        p_height = (len(p_lines) * point_line_height) + round(36 * scale)

        # Draw point container card
        draw.rounded_rectangle([margin_x, cur_y, width - margin_x, cur_y + p_height], radius=20, fill=(15, 23, 42, 215), outline=(255, 255, 255, 30), width=2)

        space_w = int(draw.textlength(' ', font=font_point)) or 1
        # Draw text with warm highlight for numbers and accent words
        for j, pline in enumerate(p_lines):
            # Calculate alignment based on language
            lw = rich_line_width(pline, font_point, draw, language, font_number_point, font_arabic_point)
            if any(contains_arabic(word) for word, _ in pline):
                right_x = width - margin_x - round(30 * scale)
            else:
                px = margin_x + round(30 * scale)
            py = cur_y + round(18 * scale) + (j * point_line_height)

            if any(contains_arabic(word) for word, _ in pline):
                for word, color in pline:
                    display_word = _display_word(word, language)
                    word_font = _font_for_word(word, font_point, font_number_point, font_arabic_point)
                    word_w = int(draw.textlength(display_word, font=word_font))
                    right_x -= word_w
                    draw.text((right_x, py), display_word, font=word_font, fill=color)
                    right_x -= space_w
            else:
                for word, color in pline:
                    display_word = _display_word(word, language)
                    word_font = _font_for_word(word, font_point, font_number_point, font_arabic_point)
                    draw.text((px, py), display_word, font=word_font, fill=color)
                    px += int(draw.textlength(display_word, font=word_font)) + space_w

        cur_y += p_height + 20

    # 5. Anti-Border Warning Template (قوالب ضد تحذير الحدود)
    if anti_border:
        # Subtle semi-transparent guides on borders (safe zones for TikTok/Reels)
        # Top safe-zone (140px for platform header)
        draw.rectangle([0, 0, width, 140], fill=(0, 0, 0, 30))
        # Bottom safe-zone (280px for sound/like buttons/caption)
        draw.rectangle([0, height - 280, width, height], fill=(0, 0, 0, 30))

    # Scene progress indicator: one segment per scene, filled as the reel advances.
    if progress is not None:
        current_scene, total_scenes = progress
        current_scene = max(1, min(int(current_scene), int(total_scenes)))
        total_scenes = max(1, int(total_scenes))
        bar_y = int(height * 0.86)
        track_left = int(width * 0.18)
        track_right = width - track_left
        gap = 8
        segment_w = (track_right - track_left - gap * (total_scenes - 1)) / total_scenes
        for index in range(total_scenes):
            segment_x = track_left + index * (segment_w + gap)
            active = index >= total_scenes - current_scene if language == "ar" else index < current_scene
            color = accent_rgb + (255,) if active else (255, 255, 255, 55)
            draw.rounded_rectangle([segment_x, bar_y, segment_x + segment_w, bar_y + 8], radius=4, fill=color)

    # Keep the CTA for the closing beat instead of repeating it on every scene.
    if include_cta:
        pill_text = "Save this for later." if language == "en" else "احفظه وارجع له وقت الحاجة"
        display_pill = _display_word(pill_text, language)
        if progress is not None:
            pill_font = get_arabic_font(round(30 * scale)) if contains_arabic(pill_text) else get_font(round(30 * scale), bold=True)
            bbox_pill = draw.textbbox((0, 0), display_pill, font=pill_font)
            pill_w = (bbox_pill[2] - bbox_pill[0]) + 64
            pill_h = 58
            pill_x = (width - pill_w) // 2
            pill_y = int(height * 0.75)
            draw.rounded_rectangle([pill_x, pill_y, pill_x + pill_w, pill_y + pill_h], radius=29, fill=(248, 250, 252, 235))
            draw.text((pill_x + 32, pill_y + 10), display_pill, font=pill_font, fill=(15, 23, 42))
        else:
            pill_font = font_arabic_footer if contains_arabic(pill_text) else font_footer
            bbox_pill = draw.textbbox((0, 0), display_pill, font=pill_font)
            pill_w = (bbox_pill[2] - bbox_pill[0]) + 40
            pill_x = (width - pill_w) // 2
            cur_y += 10
            draw.rounded_rectangle([pill_x, cur_y, pill_x + pill_w, cur_y + 44], radius=12, fill=(248, 250, 252, 230))
            draw.text((pill_x + 20, cur_y + 10), display_pill, font=pill_font, fill=(15, 23, 42))

    img_np = np.array(img)
    return ImageClip(img_np).with_duration(duration) if hasattr(ImageClip, 'with_duration') else ImageClip(img_np).set_duration(duration)


def _draw_tokens_line(draw, line, x, y, font, number_font, arabic_font, language):
    space_width = int(draw.textlength(' ', font=font)) or 1
    if any(contains_arabic(word) for word, _ in line):
        right_x = x
        for word, color in line:
            display_word = _display_word(word, language)
            word_font = _font_for_word(word, font, number_font, arabic_font)
            word_width = int(draw.textlength(display_word, font=word_font))
            right_x -= word_width
            draw.text((right_x, y), display_word, font=word_font, fill=color)
            right_x -= space_width
    else:
        left_x = x
        for word, color in line:
            display_word = _display_word(word, language)
            word_font = _font_for_word(word, font, number_font, arabic_font)
            draw.text((left_x, y), display_word, font=word_font, fill=color)
            left_x += int(draw.textlength(display_word, font=word_font)) + space_width


def render_end_card(package, size=(1080, 1920), duration=2, handle="", language="en", accent_color="#fb7185", font_scale=100, progress=None):
    """A dedicated closing card with a clear call to action and follow prompt."""
    accent_rgb = hex_to_rgb(accent_color)
    width, height = size
    scale = max(0.55, min(1.3, (float(font_scale) / 100.0) * min(width / 1080.0, height / 1920.0)))
    img = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    handle = _format_handle(handle)

    # Circular play mark keeps the end card visually tied to the brand.
    ring_radius = round(46 * scale)
    ring_x, ring_y = width // 2, int(height * 0.215)
    draw.ellipse([ring_x - ring_radius, ring_y - ring_radius, ring_x + ring_radius, ring_y + ring_radius], outline=accent_rgb + (255,), width=max(2, round(4 * scale)))
    triangle = [
        (ring_x - round(10 * scale), ring_y - round(16 * scale)),
        (ring_x - round(10 * scale), ring_y + round(16 * scale)),
        (ring_x + round(18 * scale), ring_y),
    ]
    draw.polygon(triangle, fill=accent_rgb + (255,))

    if handle:
        handle_font = get_arabic_font(round(27 * scale)) if contains_arabic(handle) else get_font(round(27 * scale), bold=True)
        handle_display = _display_word(handle, language)
        handle_box = draw.textbbox((0, 0), handle_display, font=handle_font)
        draw.text(((width - (handle_box[2] - handle_box[0])) // 2, ring_y + ring_radius + 18), handle_display, font=handle_font, fill=(226, 232, 240, 220))

    panel_top = int(height * 0.32)
    panel_bottom = int(height * 0.52)
    margin = int(width * 0.08)
    draw.rounded_rectangle([margin, panel_top, width - margin, panel_bottom], radius=round(32 * scale), fill=(15, 23, 42, 228), outline=accent_rgb + (120,), width=max(2, round(2 * scale)))

    cta_text = str(package.get("headline") or ("Save this for later" if language == "en" else "احفظ هذا وارجع له لاحقاً")).strip()
    cta_tokens = tokenize_styled(cta_text, accent_rgb, (255, 255, 255))
    cta_size = round(56 * scale)
    cta_font = get_font(cta_size, bold=True)
    cta_numbers = get_number_font(cta_size, bold=True)
    cta_arabic = get_arabic_font(cta_size) if contains_arabic(cta_text) else None
    cta_lines = wrap_tokens(cta_tokens, cta_font, int((width - 2 * margin) * 0.86), draw, language, cta_numbers, cta_arabic)
    line_height = round(76 * scale)
    start_y = panel_top + round(38 * scale)
    for line_index, line in enumerate(cta_lines):
        line_width = rich_line_width(line, cta_font, draw, language, cta_numbers, cta_arabic)
        line_x = (width - line_width) // 2
        _draw_tokens_line(draw, line, line_x + line_width if language == "ar" else line_x, start_y + line_index * line_height, cta_font, cta_numbers, cta_arabic, language)

    lowered_cta = cta_text.casefold()
    is_follow_cta = any(word in lowered_cta for word in ("تابع", "اشترك", "follow", "subscribe"))
    follow_text = ("شكراً للمشاهدة" if is_follow_cta else "تابع للمزيد") if language == "ar" else ("Thanks for watching" if is_follow_cta else "Follow for more")
    follow_size = round(30 * scale)
    follow_font = get_arabic_font(follow_size) if contains_arabic(follow_text) else get_font(follow_size, bold=True)
    follow_display = _display_word(follow_text, language)
    follow_box = draw.textbbox((0, 0), follow_display, font=follow_font)
    draw.text(((width - (follow_box[2] - follow_box[0])) // 2, start_y + len(cta_lines) * line_height + round(12 * scale)), follow_display, font=follow_font, fill=accent_rgb + (255,))

    draw.rectangle([0, 0, width, int(height * 0.073)], fill=(0, 0, 0, 30))
    draw.rectangle([0, height - int(height * 0.146), width, height], fill=(0, 0, 0, 30))

    if progress is not None:
        total_scenes = max(1, int(progress[1]))
        bar_y = int(height * 0.86)
        track_left = int(width * 0.18)
        track_right = width - track_left
        gap = 8
        segment_w = (track_right - track_left - gap * (total_scenes - 1)) / total_scenes
        for index in range(total_scenes):
            segment_x = track_left + index * (segment_w + gap)
            draw.rounded_rectangle([segment_x, bar_y, segment_x + segment_w, bar_y + 8], radius=4, fill=accent_rgb + (255,))

    img_np = np.array(img)
    clip = ImageClip(img_np)
    return clip.with_duration(duration) if hasattr(clip, "with_duration") else clip.set_duration(duration)


def build_caption_clips(spec, scene_start, scene_duration, size, language, accent_color, font_scale=100, hook_word_by_word=False):
    """Karaoke-style word captions for a point scene, drawn above the safe area."""
    kind = spec.get("kind")
    if kind != "point" and not (kind == "hook" and hook_word_by_word):
        return []

    text = str(spec.get("text") if kind == "hook" else (spec.get("caption") or "")).strip()
    if not text or (kind == "point" and _normalize_word(text) == _normalize_word(spec.get("text"))):
        return []

    words = text.split()
    if not words:
        return []

    accent_rgb = hex_to_rgb(accent_color)
    width, height = size
    scale = max(0.55, min(1.3, (float(font_scale) / 100.0) * min(width / 1080.0, height / 1920.0)))
    font_size = round(38 * scale)
    font = get_font(font_size, bold=True)
    number_font = get_number_font(font_size, bold=True)
    arabic_font = get_arabic_font(font_size) if contains_arabic(text) else None
    line_height = round(50 * scale)
    caption_height = round(230 * scale)
    max_width = int(width * 0.82)

    weights = [max(2, len(word) + 1) for word in words]
    total_weight = sum(weights) or 1
    lead = min(0.12, scene_duration * 0.08)
    available = max(0.2, scene_duration - lead - 0.08)

    clips = []
    cursor = scene_start + lead
    for index, word in enumerate(words):
        word_duration = available * weights[index] / total_weight
        group_start = (index // 4) * 4
        group = words[group_start:index + 1] if kind == "hook" else words[group_start:group_start + 4]
        active_index = len(group) - 1 if kind == "hook" else index - group_start

        tokens = [
            [item, accent_rgb if offset == active_index else (255, 255, 255)]
            for offset, item in enumerate(group)
        ]
        lines = wrap_tokens(tokens, font, max_width, ImageDraw.Draw(Image.new("RGBA", (10, 10))), language, number_font, arabic_font)
        if not lines:
            continue

        line_widths = [rich_line_width(line, font, ImageDraw.Draw(Image.new("RGBA", (10, 10))), language, number_font, arabic_font) for line in lines]
        content_width = min(max(line_widths), max_width)
        content_height = len(lines) * line_height
        pill_width = content_width + round(48 * scale)
        pill_height = content_height + round(28 * scale)

        image = Image.new("RGBA", (width, caption_height), (0, 0, 0, 0))
        draw = ImageDraw.Draw(image)
        pill_x = (width - pill_width) // 2
        pill_y = (caption_height - pill_height) // 2
        draw.rounded_rectangle(
            [pill_x, pill_y, pill_x + pill_width, pill_y + pill_height],
            radius=round(18 * scale),
            fill=(9, 13, 22, 190),
            outline=(255, 255, 255, 22),
            width=1,
        )

        text_y = pill_y + round(14 * scale)
        for line_index, line in enumerate(lines):
            line_width = line_widths[line_index]
            line_x = (width - line_width) // 2
            _draw_tokens_line(
                draw,
                line,
                line_x + line_width if language == "ar" else line_x,
                text_y + line_index * line_height,
                font,
                number_font,
                arabic_font,
                language,
            )

        clip = ImageClip(np.array(image))
        clip = clip.with_duration(max(0.08, word_duration))
        clip = clip.with_start(cursor)
        caption_y = 0.47 if kind == "hook" else 0.70
        clip = clip.with_position(("center", int(height * caption_y)))
        clips.append(clip)
        cursor += word_duration

    return clips


def _srt_timestamp(seconds):
    milliseconds = int(round(max(0.0, float(seconds)) * 1000))
    hours, remainder = divmod(milliseconds, 3_600_000)
    minutes, remainder = divmod(remainder, 60_000)
    secs, millis = divmod(remainder, 1000)
    return f"{hours:02d}:{minutes:02d}:{secs:02d},{millis:03d}"


def write_srt(cues, path):
    """Write sentence-level subtitles that can be uploaded to any platform."""
    directory = os.path.dirname(os.path.abspath(path))
    os.makedirs(directory, exist_ok=True)

    blocks = []
    for index, (start, end, text) in enumerate(cues, start=1):
        clean = " ".join(str(text).split())
        if not clean:
            continue
        blocks.append(f"{index}\n{_srt_timestamp(start)} --> {_srt_timestamp(end)}\n{clean}\n")

    with open(path, "w", encoding="utf-8") as handle:
        handle.write("\n".join(blocks))


def animate_reference_overlay(overlay, duration, staged=True, motion="vertical"):
    """Reveal the card with a restrained lift; short scenes use one quick sweep."""
    width, height = overlay.size
    base_mask = overlay.mask.get_frame(0).astype(np.float32) if overlay.mask is not None else np.ones((height, width), dtype=np.float32)
    feather = max(18, int(height * 0.025))

    if motion in {"slide_right", "slide_left"}:
        direction = "right" if motion == "slide_right" else "left"
        mask = VideoClip(
            frame_function=lambda t: _horizontal_reveal_mask_frame(base_mask, t, direction, 0.48, max(12, int(width * 0.02))),
            is_mask=True,
            duration=duration,
        )
    elif staged:
        mask = VideoClip(
            frame_function=lambda t: _staged_text_reveal_mask_frame(base_mask, t, feather),
            is_mask=True,
            duration=duration,
        )
    else:
        reveal_duration = min(0.55, max(0.25, float(duration) * 0.45))
        mask = VideoClip(
            frame_function=lambda t: _vertical_reveal_mask_frame(base_mask, t, 0, height, 0.0, reveal_duration, feather),
            is_mask=True,
            duration=duration,
        )
    animated = overlay.with_mask(mask)

    def lift_position(time):
        progress = np.clip(float(time) / 0.42, 0.0, 1.0)
        eased_progress = progress * progress * (3.0 - 2.0 * progress)
        if motion == "slide_right":
            x_offset = width * 0.10 * (1.0 - eased_progress)
        elif motion == "slide_left":
            x_offset = -width * 0.10 * (1.0 - eased_progress)
        else:
            x_offset = 0.0
        return (int(round(x_offset)), int(round(18 * (1.0 - eased_progress))))

    return animated.with_position(lift_position)


def apply_scene_transition(overlay, duration, fade_in=0.18, fade_out=0.24, transition="fade", direction="right"):
    """Apply a restrained fade, edge wipe, or slide without changing clip timing."""
    base_mask = overlay.mask
    if base_mask is None:
        return overlay
    width = overlay.size[0]

    def transition_mask(time):
        current = np.asarray(base_mask.get_frame(time), dtype=np.float32)
        fade_in_progress = min(max(float(time) / max(fade_in, 1e-6), 0.0), 1.0)
        fade_out_start = max(0.0, float(duration) - fade_out)
        fade_out_progress = min(max((float(time) - fade_out_start) / max(fade_out, 1e-6), 0.0), 1.0)
        envelope = (fade_in_progress * (1.0 - fade_out_progress))
        if transition == "wipe":
            wipe_progress = np.clip(float(time) / max(fade_in, 1e-6), 0.0, 1.0)
            edge = width * (1.0 - wipe_progress) if direction == "right" else width * wipe_progress
            columns = np.arange(width, dtype=np.float32)
            feather = max(12.0, width * 0.015)
            if direction == "right":
                wipe = np.clip((columns - edge + feather) / feather, 0.0, 1.0)
            else:
                wipe = np.clip((edge + feather - columns) / feather, 0.0, 1.0)
            current = current * wipe[None, :]
        return np.clip(current * envelope, 0.0, 1.0)

    mask = VideoClip(frame_function=transition_mask, is_mask=True, duration=duration)
    transitioned = overlay.with_mask(mask)
    if transition == "slide":
        base_position = getattr(overlay, "pos", None)

        def slide_position(time):
            original = base_position(time) if callable(base_position) else (0, 0)
            progress = np.clip(float(time) / max(fade_in, 1e-6), 0.0, 1.0)
            eased = progress * progress * (3.0 - 2.0 * progress)
            offset = width * 0.12 * (1.0 - eased) * (1.0 if direction == "right" else -1.0)
            return (int(round(original[0] + offset)), original[1])

        transitioned = transitioned.with_position(slide_position)
    return transitioned


def build_vignette_overlay(size, duration, strength=0.32):
    """Soften the frame edges so the text card owns the center of attention."""
    width, height = size
    y, x = np.ogrid[:height, :width]
    nx = (x - width / 2.0) / (width / 2.0)
    ny = (y - height / 2.0) / (height / 2.0)
    distance = np.sqrt(nx * nx + ny * ny)
    alpha = np.clip((distance - 0.55) / 0.45, 0.0, 1.0) * strength

    rgba = np.zeros((height, width, 4), dtype=np.uint8)
    rgba[..., 3] = (alpha * 255).astype(np.uint8)
    clip = ImageClip(rgba)

    return clip.with_duration(duration) if hasattr(clip, "with_duration") else clip.set_duration(duration)


def build_smoke_overlay(size, duration, seed=17, opacity=0.12):
    """Create deterministic, soft volumetric smoke from drifting Gaussian wisps."""
    width, height = size
    grid_width = 96
    grid_height = max(96, int(round(grid_width * height / max(width, 1))))
    yy, xx = np.mgrid[0:grid_height, 0:grid_width].astype(np.float32)
    normalized_x = xx / max(grid_width - 1, 1)
    normalized_y = yy / max(grid_height - 1, 1)
    rng = np.random.default_rng(seed)
    wisps = [
        (float(rng.uniform(-0.15, 0.95)), float(rng.uniform(0.10, 0.90)),
         float(rng.uniform(0.08, 0.18)), float(rng.uniform(0.14, 0.30)),
         float(rng.uniform(-0.045, 0.045)), float(rng.uniform(-0.025, 0.025)))
        for _ in range(6)
    ]

    def smoke_field(time):
        field = np.zeros_like(normalized_x, dtype=np.float32)
        for index, (origin_x, origin_y, radius_x, radius_y, drift_x, drift_y) in enumerate(wisps):
            center_x = (origin_x + drift_x * float(time)) % 1.15 - 0.075
            center_y = (origin_y + drift_y * float(time)) % 1.15 - 0.075
            pulse = 0.82 + 0.18 * np.sin(float(time) * 0.9 + index)
            distance = (((normalized_x - center_x) / radius_x) ** 2
                        + ((normalized_y - center_y) / radius_y) ** 2)
            field += pulse * np.exp(-distance * 1.7)
        return np.clip(field / 2.4, 0.0, 1.0)

    def frame(time):
        field = smoke_field(time)
        low_rgb = np.empty((grid_height, grid_width, 3), dtype=np.uint8)
        low_rgb[:, :, 0] = 205
        low_rgb[:, :, 1] = 218
        low_rgb[:, :, 2] = 232
        return np.asarray(Image.fromarray(low_rgb).resize((width, height), Image.Resampling.BILINEAR))

    def mask_frame(time):
        alpha = np.uint8(np.clip(smoke_field(time) * float(opacity) * 255.0, 0.0, 255.0))
        resized = Image.fromarray(alpha).resize((width, height), Image.Resampling.BILINEAR)
        return np.asarray(resized, dtype=np.float32) / 255.0

    smoke = VideoClip(frame_function=frame, duration=duration)
    mask = VideoClip(frame_function=mask_frame, is_mask=True, duration=duration)
    return smoke.with_mask(mask)


def process_motion_clip(filepath, target_duration, resolution=(1080, 1920), motion="zoom_in"):
    """Crop to the selected aspect ratio and apply deterministic zoom/pan motion."""
    is_still_image = Path(filepath).suffix.lower() in {".jpg", ".jpeg", ".png", ".webp"}
    source = ImageClip(filepath) if is_still_image else VideoFileClip(filepath)
    if is_still_image:
        source = source.with_duration(float(target_duration)) if hasattr(source, "with_duration") else source.set_duration(float(target_duration))
    source_w, source_h = source.size
    target_w, target_h = resolution
    target_ratio = target_w / target_h

    if source_w / source_h > target_ratio:
        base_crop_h = source_h
        base_crop_w = max(1, int(source_h * target_ratio))
    else:
        base_crop_w = source_w
        base_crop_h = max(1, int(source_w / target_ratio))

    motion = motion if motion in {"static", "zoom_in", "pan", "parallax"} else "static"

    def make_frame(time):
        frame = source.get_frame(min(max(float(time), 0.0), max(float(source.duration) - 1e-4, 0.0)))
        progress = min(max(float(time) / max(float(source.duration), 1e-6), 0.0), 1.0)
        if motion == "zoom_in":
            zoom = 1.0 + (0.08 * progress)
            center_offset = 0.0
            vertical_offset = 0.0
        elif motion == "pan":
            zoom = 1.08
            center_offset = (progress - 0.5) * base_crop_w * 0.12
            vertical_offset = 0.0
        elif motion == "parallax":
            zoom = 1.10 + 0.015 * np.sin(progress * np.pi)
            center_offset = np.sin(progress * 2.0 * np.pi) * base_crop_w * 0.035
            vertical_offset = np.sin(progress * np.pi) * base_crop_h * 0.022
        else:
            zoom = 1.0
            center_offset = 0.0
            vertical_offset = 0.0

        crop_w = min(source_w, max(1, int(base_crop_w / zoom)))
        crop_h = min(source_h, max(1, int(base_crop_h / zoom)))
        center_x = source_w / 2.0 + center_offset
        x1 = max(0, min(source_w - crop_w, int(round(center_x - crop_w / 2.0))))
        center_y = source_h / 2.0 + vertical_offset
        y1 = max(0, min(source_h - crop_h, int(round(center_y - crop_h / 2.0))))
        image = Image.fromarray(np.asarray(frame, dtype=np.uint8))
        image = image.crop((x1, y1, x1 + crop_w, y1 + crop_h))
        image = image.resize((target_w, target_h), Image.Resampling.LANCZOS)
        return np.asarray(image)

    clip = VideoClip(frame_function=make_frame, duration=float(source.duration))
    if getattr(source, "audio", None) is not None:
        clip = clip.with_audio(source.audio) if hasattr(clip, "with_audio") else clip.set_audio(source.audio)
    clip._source_video = source

    if clip.duration > target_duration:
        result = clip.subclipped(0, target_duration) if hasattr(clip, "subclipped") else clip.subclip(0, target_duration)
    elif clip.duration < target_duration:
        repeats = int(target_duration / max(float(clip.duration), 0.1)) + 1
        looped = concatenate_videoclips([clip] * repeats, method="compose")
        result = looped.subclipped(0, target_duration) if hasattr(looped, "subclipped") else looped.subclip(0, target_duration)
    else:
        result = clip

    result._source_video = source
    return result


def validate_input_video(filepath):
    """Return a clear validation error for unreadable video or still-image inputs."""
    path = str(filepath)
    if not os.path.isfile(path):
        return "Input media does not exist: " + path
    if os.path.getsize(path) <= 0:
        return "Input media is empty: " + path

    if Path(path).suffix.lower() in {".jpg", ".jpeg", ".png", ".webp"}:
        try:
            with Image.open(path) as image:
                image.load()
                width, height = image.size
                if width <= 0 or height <= 0:
                    return "Input image has no usable pixels: " + path
        except Exception as exc:
            return "Input image cannot be decoded: " + path + " (" + str(exc) + ")"
        return None

    source = None
    try:
        source = VideoFileClip(path)
        duration = float(source.duration or 0)
        width, height = source.size
        if duration <= 0 or int(width) <= 0 or int(height) <= 0:
            return "Input video has no usable frames: " + path
        # Force MoviePy/FFmpeg to decode the first frame so truncated files fail early.
        source.get_frame(0)
    except Exception as exc:
        return "Input video cannot be decoded: " + path + " (" + str(exc) + ")"
    finally:
        if source is not None:
            try:
                source.close()
            except Exception:
                pass
    return None

def build_scene_specs(package, topic, language="en"):
    """Turn a storyboard package into hook, point, and CTA scene specs."""
    specs = []
    hook = str(package.get("hook") or package.get("headline") or topic or "").strip()
    specs.append({"text": hook, "caption": hook, "emphasis": str(package.get("emphasis") or ""), "kind": "hook", "hint": 0.9, "min": 0.9})
    seen = {_normalize_word(hook)}

    points = []
    for scene in package.get("scenes") or []:
        text = str(scene.get("text") or "").strip()
        if not text or _normalize_word(text) in seen:
            continue
        seen.add(_normalize_word(text))
        points.append({
            "text": text,
            "caption": str(scene.get("caption") or text).strip(),
            "emphasis": str(scene.get("emphasis") or "").strip(),
            "kind": "point",
            "hint": scene.get("duration"),
            "min": 1.25,
        })

    if not points:
        for index in range(1, 4):
            point = str(package.get(f"point_{index}", "")).strip()
            point = re.sub(r"^\s*\d{1,2}[.)،:-]?\s*", "", point)
            if point:
                points.append({"text": point, "caption": point, "emphasis": "", "kind": "point", "hint": None, "min": 1.25})

    specs.extend(points[:4])
    cta = str(package.get("cta") or ("Save this for later" if language == "en" else "احفظ هذا وارجع له لاحقاً")).strip()
    specs.append({"text": cta, "caption": "", "emphasis": "", "kind": "cta", "hint": 1.4, "min": 1.3})

    return specs


def plan_scene_durations(texts, requested_duration, voiceover=False, hints=None, minimums=None):
    """Give each scene enough screen time to be read comfortably."""
    texts = [str(text) for text in texts if str(text).strip()]
    if not texts:
        return []

    requested = max(1.0, float(requested_duration))
    hints = list(hints or [0] * len(texts))
    minimums = list(minimums or [1.25] * len(texts))
    hints = (hints + [0] * len(texts))[:len(texts)]
    minimums = (minimums + [1.25] * len(texts))[:len(texts)]

    if voiceover:
        weights = [max(4, len(text.split())) for text in texts]
        total = sum(weights) or 1
        return [requested * weight / total for weight in weights]

    weights = []
    for text, hint in zip(texts, hints):
        weight = max(3.0, len(text.split()) / 2.8)
        if hint:
            weight = max(weight, min(9.0, float(hint) * 2.8))
        weights.append(weight)

    required = min(60.0, max(requested, sum(minimums)))
    total = sum(weights) or 1
    durations = [max(minimum, required * weight / total) for minimum, weight in zip(minimums, weights)]
    factor = required / (sum(durations) or 1)

    return [duration * factor for duration in durations]


def build_preview_image(args):
    """Render one real scene card to an image for the wizard preview."""
    quality = int(getattr(args, "quality", 1080))
    if quality not in (720, 1080):
        quality = 1080
    resolution = (
        (quality, round(quality * 16 / 9))
        if args.aspect_ratio == "9:16"
        else ((quality, quality) if args.aspect_ratio == "1:1" else (round(quality * 16 / 9), quality))
    )

    points = [str(point).strip() for point in (getattr(args, "custom_points", None) or []) if str(point).strip()]
    headline = str(getattr(args, "custom_headline", None) or args.topic or "").strip()
    if not headline:
        headline = "معاينة حقيقية" if args.language == "ar" else "Live preview"

    if points:
        package = {"headline": points[0], "emphasis": "", "point_1": "", "point_2": "", "point_3": ""}
        kicker = f"01 / {max(1, len(points)):02d}"
        include_branding = False
    else:
        package = {"headline": headline, "emphasis": "", "point_1": "", "point_2": "", "point_3": ""}
        kicker = "HOOK" if args.language == "en" else "هوك"
        include_branding = True

    overlay = render_reference_card(
        package=package,
        size=resolution,
        duration=1,
        template=args.template,
        handle=args.handle,
        anti_border=args.anti_border,
        language=args.language,
        accent_color=getattr(args, "accent_color", "#fb7185"),
        font_scale=getattr(args, "font_scale", 100),
        include_branding=include_branding,
        include_cta=False,
        kicker=kicker,
        progress=(1, max(2, len(points) + 2)),
    )

    source = getattr(overlay, "img", None)
    frame = np.asarray(source if source is not None else overlay.get_frame(0), dtype=np.uint8)
    if frame.shape[2] == 3:
        foreground = Image.fromarray(frame).convert("RGBA")
        if overlay.mask is not None:
            alpha = np.asarray(overlay.mask.get_frame(0))
            if alpha.dtype != np.uint8:
                alpha = np.clip(alpha * 255.0, 0, 255).astype(np.uint8)
            foreground.putalpha(Image.fromarray(alpha))
    else:
        foreground = Image.fromarray(frame, "RGBA")

    width, height = resolution
    y = np.linspace(0, 1, height, dtype=np.float32)[:, None, None]
    top = np.array([16, 24, 40], dtype=np.float32)
    bottom = np.array([5, 7, 12], dtype=np.float32)
    background = (top * (1 - y) + bottom * y)
    background = np.repeat(background, width, axis=1).astype(np.uint8)
    background = Image.fromarray(background).convert("RGBA")
    composed = Image.alpha_composite(background, foreground)

    output = str(args.preview_image)
    os.makedirs(os.path.dirname(os.path.abspath(output)), exist_ok=True)
    if output.lower().endswith((".jpg", ".jpeg")):
        composed.convert("RGB").save(output, quality=92)
    else:
        composed.save(output)

    return output


def build_ultra_reel(args):
    quality = int(getattr(args, "quality", 1080))
    if quality not in (720, 1080):
        quality = 1080
    resolution = (
        (quality, round(quality * 16 / 9))
        if args.aspect_ratio == "9:16"
        else ((quality, quality) if args.aspect_ratio == "1:1" else (round(quality * 16 / 9), quality))
    )
    progress_file = getattr(args, "progress_file", None)
    custom_pts = args.custom_points if getattr(args, "custom_points", None) else []
    custom_headline = str(getattr(args, "custom_headline", None) or "").strip()
    if getattr(args, "manual_content", False):
        if not custom_headline or not custom_pts:
            raise ValueError("Manual content requires a headline and at least one script line.")
        scenes = [
            {"text": str(point).strip(), "caption": str(point).strip(), "emphasis": "", "duration": None, "kind": "point"}
            for point in custom_pts if str(point).strip()
        ]
        package = {
            "headline": custom_headline,
            "hook": custom_headline,
            "scenes": scenes,
            "point_1": scenes[0]["caption"] if scenes else "",
            "point_2": scenes[1]["caption"] if len(scenes) > 1 else "",
            "point_3": scenes[2]["caption"] if len(scenes) > 2 else "",
            "cta": "Save this for later" if args.language == "en" else "احفظ هذا وارجع له لاحقاً",
            "caption": "",
            "hashtags": "",
            "score": None,
            "content_source": "manual",
            "ai_model": None,
        }
    else:
        print(f"[Engine Ultra] Generating package for topic: '{args.topic}'")
        _report_progress(progress_file, "writing_script", 30)
        base_package = generate_ai_package(
            topic=args.topic,
            niche=args.niche,
            goal=args.goal,
            language=args.language,
            style=args.style,
            gemini_api_keys=getattr(args, "gemini_api_keys", None),
            user_prompt=getattr(args, "user_prompt", ""),
            format_type=getattr(args, "format_type", "full_idea"),
            target_country=getattr(args, "target_country", "Global"),
            progress_file=progress_file,
        )
        if custom_headline or custom_pts:
            package = {
                "headline": custom_headline or base_package.get("headline", args.topic),
                "point_1": custom_pts[0] if len(custom_pts) > 0 else base_package.get("point_1", ""),
                "point_2": custom_pts[1] if len(custom_pts) > 1 else base_package.get("point_2", ""),
                "point_3": custom_pts[2] if len(custom_pts) > 2 else base_package.get("point_3", ""),
                "caption": base_package.get("caption", ""),
                "hashtags": base_package.get("hashtags", ""),
                "score": None,
                "content_source": base_package.get("content_source", "gemini"),
                "ai_model": base_package.get("ai_model"),
                "provider_key_fingerprint": base_package.get("provider_key_fingerprint"),
                "provider_key_failure_fingerprints": base_package.get("provider_key_failure_fingerprints", []),
            }
        else:
            package = base_package

    _report_progress(progress_file, "preparing_video", 40)

    # Process source videos
    input_errors = []
    valid_clips = []
    for filepath in args.input:
        error = validate_input_video(filepath)
        if error is None:
            valid_clips.append(filepath)
        else:
            input_errors.append(error)
    if not valid_clips:
        detail = input_errors[0] if input_errors else "No input video files were provided."
        raise ValueError("No valid input video files provided to compile reel. " + detail)

    voiceover_path = None
    voiceover_clip = None
    processed = []
    overlays = []
    smoke_overlays = []
    auxiliary_audio = []
    concatenated = None
    final_video = None
    render_duration = float(args.duration)
    voiceover_enabled = bool(getattr(args, "voiceover", False))

    try:
        if voiceover_enabled:
            api_keys = _environment_keys("AIVIDEO_ELEVENLABS_KEYS_JSON")
            legacy_api_key = os.getenv("ELEVENLABS_API_KEY", "").strip()
            if legacy_api_key and legacy_api_key not in api_keys:
                api_keys.append(legacy_api_key)
            voice_id_variable = "ELEVENLABS_VOICE_ID_AR" if args.language == "ar" else "ELEVENLABS_VOICE_ID_EN"
            voice_id = os.getenv(voice_id_variable, "").strip()
            narration_text = narration_text_from_package(package)
            descriptor, voiceover_path = tempfile.mkstemp(prefix="aivideo-voice-", suffix=".mp3")
            os.close(descriptor)
            generate_elevenlabs_voiceover(
                narration_text,
                api_keys,
                voice_id,
                voiceover_path,
                tone=getattr(args, "voiceover_tone", "neutral"),
            )
            voiceover_clip = AudioFileClip(voiceover_path)
            render_duration = max(render_duration, float(voiceover_clip.duration) + 0.35)

        scene_specs = build_scene_specs(package, args.topic, args.language)
        plan_texts = [spec["caption"] or spec["text"] for spec in scene_specs]
        scene_durations = plan_scene_durations(
            plan_texts,
            render_duration,
            voiceover_enabled,
            hints=[spec["hint"] for spec in scene_specs],
            minimums=[spec["min"] for spec in scene_specs],
        )
        render_duration = sum(scene_durations)
        script_text = "\n".join(spec["caption"] or spec["text"] for spec in scene_specs if (spec["caption"] or spec["text"]).strip())

        srt_output = getattr(args, "srt_output", None)
        if srt_output:
            cues = []
            cursor = 0.0
            for spec, scene_duration in zip(scene_specs, scene_durations):
                cue_text = spec["caption"] or spec["text"]
                cues.append((cursor, cursor + scene_duration, cue_text))
                cursor += scene_duration
            write_srt(cues, srt_output)

        per_dur = render_duration / len(valid_clips)
        processed = [process_motion_clip(f, per_dur, resolution=resolution, motion=args.camera_motion) for f in valid_clips]
        concatenated = concatenate_videoclips(processed, method="compose")
        _report_progress(progress_file, "creating_scenes", 55)

        total_scenes = len(scene_specs)
        total_points = max(1, sum(1 for spec in scene_specs if spec["kind"] == "point"))
        point_index = 0
        elapsed = 0.0
        for index, spec in enumerate(scene_specs):
            scene_duration = scene_durations[index]
            scene_start = elapsed

            if spec["kind"] == "cta":
                overlay = render_end_card(
                    {"headline": spec["text"]},
                    size=resolution,
                    duration=scene_duration,
                    handle=args.handle,
                    language=args.language,
                    accent_color=getattr(args, 'accent_color', '#fb7185'),
                    font_scale=getattr(args, 'font_scale', 100),
                    progress=(index + 1, total_scenes),
                )
            else:
                if spec["kind"] == "hook":
                    kicker = "HOOK" if args.language == "en" else "هوك"
                else:
                    point_index += 1
                    kicker = f"{point_index:02d} / {total_points:02d}"

                overlay = render_reference_card(
                    package={
                        "headline": "" if (spec["kind"] == "hook" and getattr(args, "hook_word_by_word", False)) else spec["text"],
                        "emphasis": spec["emphasis"] if getattr(args, "highlight_important", True) else "",
                        "point_1": "",
                        "point_2": "",
                        "point_3": "",
                    },
                    size=resolution,
                    duration=scene_duration,
                    template=args.template,
                    handle=args.handle,
                    anti_border=args.anti_border,
                    language=args.language,
                    accent_color=getattr(args, 'accent_color', '#fb7185'),
                    font_scale=getattr(args, 'font_scale', 100),
                    include_branding=(spec["kind"] == "hook"),
                    include_cta=False,
                    kicker=kicker,
                    progress=(index + 1, total_scenes),
                )

            overlay = animate_reference_overlay(
                overlay,
                scene_duration,
                staged=False,
                motion=getattr(args, "text_motion", "vertical"),
            )
            template_profile = {
                "cinematic": (0.28, 0.36),
                "clean_news": (0.12, 0.18),
                "quote_card": (0.22, 0.30),
                "classic": (0.18, 0.24),
            }.get(args.template, (0.18, 0.24))
            requested_transition = getattr(args, "scene_transition", "template")
            if requested_transition == "template":
                transition_mode, transition_profile = "fade", template_profile
            elif requested_transition == "varied":
                transition_mode = ("fade", "wipe", "slide")[index % 3]
                transition_profile = template_profile if transition_mode == "fade" else (0.34, 0.20)
            else:
                transition_mode = requested_transition
                transition_profile = (0.34, 0.20) if transition_mode == "wipe" else (0.24, 0.20)
            direction = "right" if index % 2 == 0 else "left"
            overlay = apply_scene_transition(
                overlay,
                scene_duration,
                *transition_profile,
                transition=transition_mode,
                direction=direction,
            )
            overlay = overlay.with_start(scene_start) if hasattr(overlay, "with_start") else overlay.set_start(scene_start)
            overlays.append(overlay)

            hook_word_by_word = bool(getattr(args, "hook_word_by_word", False))
            if getattr(args, "captions", True) or (spec["kind"] == "hook" and hook_word_by_word):
                overlays.extend(
                    build_caption_clips(
                        spec,
                        scene_start,
                        scene_duration,
                        resolution,
                        args.language,
                        getattr(args, 'accent_color', '#fb7185'),
                        getattr(args, 'font_scale', 100),
                        hook_word_by_word=hook_word_by_word,
                    )
                )

            elapsed += scene_duration
            _report_progress(progress_file, "animating_text", 55 + int(15 * (index + 1) / max(total_scenes, 1)))

        _report_progress(progress_file, "exporting_mp4", 78)
        layers = [concatenated]
        dim_value = max(0, min(80, int(getattr(args, "background_dimming", 35))))
        if dim_value > 0:
            dim_layer = ColorClip(size=resolution, color=(0, 0, 0), duration=render_duration)
            opacity = dim_value / 100.0
            dim_layer = dim_layer.with_opacity(opacity) if hasattr(dim_layer, "with_opacity") else dim_layer.set_opacity(opacity)
            layers.append(dim_layer)
        smoke_profile = {
            "cinematic": (0.14, 0.075),
            "clean_news": (0.035, 0.018),
            "quote_card": (0.085, 0.04),
            "classic": (0.10, 0.055),
        }.get(args.template, (0.10, 0.055))
        smoke_overlays = [
            build_smoke_overlay(resolution, render_duration, seed=17, opacity=smoke_profile[0]),
            build_smoke_overlay(resolution, render_duration, seed=43, opacity=smoke_profile[1]),
        ]
        layers.extend(smoke_overlays)
        layers.append(build_vignette_overlay(resolution, render_duration))
        layers.extend(overlays)

        final_video = CompositeVideoClip(layers)
        final_video = final_video.with_duration(render_duration) if hasattr(final_video, 'with_duration') else final_video.set_duration(render_duration)

        audio_layers = []
        source_audio = concatenated.audio
        if source_audio is not None:
            source_level = 0.28 if voiceover_enabled else 0.75
            source_audio = source_audio.with_volume_scaled(source_level) if hasattr(source_audio, 'with_volume_scaled') else source_audio.volumex(source_level)
            source_audio = fade_audio_edges(source_audio, fade_in=0.12, fade_out=0.28)
            audio_layers.append(source_audio)

        music_source = args.music
        if not music_source and getattr(args, "auto_music", True):
            try:
                music_source = ensure_ambient_bed()
            except Exception as exc:
                print(f"[Audio Warning] Could not prepare the ambient bed: {exc}")

        if music_source and os.path.exists(music_source):
            bg_audio = AudioFileClip(music_source)
            auxiliary_audio.append(bg_audio)
            if bg_audio.duration < render_duration:
                repeats = int(render_duration / max(float(bg_audio.duration), 0.1)) + 1
                bg_audio = concatenate_audioclips([bg_audio] * repeats)
                auxiliary_audio.append(bg_audio)
            bg_audio = bg_audio.subclipped(0, render_duration) if hasattr(bg_audio, 'subclipped') else bg_audio.subclip(0, render_duration)
            if voiceover_clip is not None:
                bg_audio = duck_music_to_voice(bg_audio, voiceover_clip, render_duration, base_gain=0.16, duck_gain=0.06)
            else:
                music_level = 0.16 if source_audio is not None else 0.30
                bg_audio = bg_audio.with_volume_scaled(music_level) if hasattr(bg_audio, 'with_volume_scaled') else bg_audio.volumex(music_level)
            bg_audio = fade_audio_edges(bg_audio, fade_in=0.35, fade_out=0.55)
            audio_layers.append(bg_audio)

        if voiceover_clip is not None:
            voice_layer = voiceover_clip.with_volume_scaled(0.96) if hasattr(voiceover_clip, 'with_volume_scaled') else voiceover_clip.volumex(0.96)
            voice_layer = fade_audio_edges(voice_layer, fade_in=0.08, fade_out=0.24)
            audio_layers.append(voice_layer)

        if audio_layers:
            mixed_audio = CompositeAudioClip(audio_layers).with_duration(render_duration)
            final_video = final_video.with_audio(mixed_audio) if hasattr(final_video, 'with_audio') else final_video.set_audio(mixed_audio)

        os.makedirs(os.path.dirname(os.path.abspath(args.output)), exist_ok=True)
        _report_progress(progress_file, "exporting_mp4", 82)
        final_video.write_videofile(
            args.output,
            fps=30,
            codec='libx264',
            audio_codec='aac',
            audio_bitrate='192k',
            ffmpeg_params=['-crf', '19', '-preset', 'veryfast', '-movflags', '+faststart', '-pix_fmt', 'yuv420p'],
            threads=4,
            logger=None
        )
        _report_progress(progress_file, "finalizing", 96)
        if audio_layers:
            normalize_loudness(args.output)
        thumbnail_path = getattr(args, "thumbnail", None)
        if thumbnail_path:
            write_thumbnail(args.output, thumbnail_path, render_duration)
        _report_progress(progress_file, "completed", 100)
    finally:
        clips_to_close = [final_video, concatenated, *overlays, *smoke_overlays, *processed, *auxiliary_audio, voiceover_clip]
        for clip in clips_to_close:
            if clip is None:
                continue
            try:
                clip.close()
            except Exception:
                pass
            source_clip = getattr(clip, "_source_video", None)
            if source_clip is not None:
                try:
                    source_clip.close()
                except Exception:
                    pass
        if voiceover_path and os.path.exists(voiceover_path):
            os.unlink(voiceover_path)

    return {
        "status": "success",
        "output_path": args.output,
        "headline": package.get("headline"),
        "script": script_text,
        "caption": package.get("caption"),
        "hashtags": package.get("hashtags"),
        "score": package.get("score"),
        "content_source": package.get("content_source", "gemini"),
        "ai_model": package.get("ai_model"),
        "thumbnail_path": getattr(args, "thumbnail", None),
        "subtitle_path": getattr(args, "srt_output", None),
        "duration": render_duration,
        "language": args.language,
        "template": args.template,
        "niche": args.niche,
        "goal": args.goal
    }

def main():
    parser = argparse.ArgumentParser(description="AI Smart Reels Studio Engine v3.0 Ultra")
    parser.add_argument("--input", "-i", nargs="+", default=[])
    parser.add_argument("--topic", "-t", default="")
    parser.add_argument("--user-prompt", default="")
    parser.add_argument("--output", "-o", default="output_ultra.mp4")
    parser.add_argument("--duration", "-d", type=float, default=6.0)
    parser.add_argument("--language", "-l", default="en", choices=["en", "ar"])
    parser.add_argument("--niche", default="productivity")
    parser.add_argument("--goal", default="followers")
    parser.add_argument("--target-country", default="Global", choices=["US", "UK", "Canada", "Australia", "Global"])
    parser.add_argument("--style", default="engaging")
    parser.add_argument("--format-type", default="full_idea", choices=["full_idea", "quick_tip", "list", "story"])
    parser.add_argument("--template", default="classic", choices=["classic", "clean_news", "cinematic", "quote_card", "bold_stripe"])
    parser.add_argument("--handle", default="")
    parser.add_argument("--aspect-ratio", default="9:16", choices=["9:16", "1:1", "16:9"])
    parser.add_argument("--camera-motion", default="zoom_in", choices=["static", "zoom_in", "pan", "parallax"])
    parser.add_argument("--text-motion", default="vertical", choices=["vertical", "slide_right", "slide_left"])
    parser.add_argument("--scene-transition", default="template", choices=["template", "fade", "wipe", "slide", "varied"])
    parser.add_argument("--hook-word-by-word", action="store_true", help="Reveal the hook word by word with the current word highlighted.")
    parser.add_argument("--no-highlight-important", dest="highlight_important", action="store_false", default=True, help="Disable accent coloring for emphasized words.")
    parser.add_argument("--font-scale", type=int, default=100)
    parser.add_argument("--background-dimming", type=int, default=35)
    parser.add_argument("--anti-border", action="store_true")
    parser.add_argument("--accent-color", default="#fb7185")
    parser.add_argument("--music", "-m", default=None)
    parser.add_argument("--voiceover", action="store_true")
    parser.add_argument("--voiceover-tone", default="neutral", choices=["neutral", "calm", "warm", "energetic"])
    parser.add_argument("--gemini-api-key", "-g", action="append", default=None, dest="gemini_api_keys")
    parser.add_argument("--custom-headline", default=None)
    parser.add_argument("--custom-points", nargs="*", default=None)
    parser.add_argument("--manual-content", action="store_true", help="Use the supplied headline and points without contacting Gemini.")
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--progress-file", default=None, help="Write render progress as JSON for the queue worker.")
    parser.add_argument("--quality", type=int, default=1080, choices=[720, 1080], help="Vertical resolution tier.")
    parser.add_argument("--thumbnail", default=None, help="Write a JPG cover image next to the video.")
    parser.add_argument("--no-auto-music", dest="auto_music", action="store_false", default=True, help="Disable the bundled ambient music bed.")
    parser.add_argument("--no-captions", dest="captions", action="store_false", default=True, help="Disable burned-in word captions.")
    parser.add_argument("--preview-image", default=None, help="Render one scene card to an image and exit.")
    parser.add_argument("--srt-output", default=None, help="Write sentence-level SRT subtitles next to the video.")

    args = parser.parse_args()

    if args.preview_image:
        try:
            build_preview_image(args)
        except Exception as exc:
            print(f"[Preview Error] {exc}", file=sys.stderr)
            sys.exit(1)
        return

    if not args.input:
        parser.error("--input is required unless --preview-image is used")
    if not args.topic and not args.manual_content:
        parser.error("--topic is required unless --preview-image is used")
    if args.manual_content and (not args.custom_headline or not args.custom_points):
        parser.error("--manual-content requires --custom-headline and at least one --custom-points value")

    if not 3 <= args.duration <= 60:
        parser.error("--duration must be between 3 and 60 seconds")
    if not 80 <= args.font_scale <= 130:
        parser.error("--font-scale must be between 80 and 130")
    if not 0 <= args.background_dimming <= 80:
        parser.error("--background-dimming must be between 0 and 80")

    try:
        res = build_ultra_reel(args)
        if args.json:
            print("\n__JSON_OUTPUT__" + json.dumps(res))
    except Exception as exc:
        print(f"[Engine Ultra Error] {exc}", file=sys.stderr)
        if args.json:
            print("\n__JSON_OUTPUT__" + json.dumps({"status": "error", "message": str(exc)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
