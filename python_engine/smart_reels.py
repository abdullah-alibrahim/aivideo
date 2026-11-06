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
import unicodedata
import tempfile
import re
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

from dotenv import load_dotenv

load_dotenv()

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


DEFAULT_FONTS = [
    "/System/Library/Fonts/Supplemental/Arial Bold.ttf",
    "/System/Library/Fonts/Supplemental/Arial.ttf",
    "/System/Library/Fonts/Helvetica.ttc",
    "/System/Library/Fonts/GeezaPro.ttc",
    "/System/Library/Fonts/SFArabic.ttf",
    "/Library/Fonts/Arial.ttf"
]

ARABIC_FONTS = [
    os.getenv("AIVIDEO_ARABIC_FONT", ""),
    "/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf",
    "/usr/share/fonts/truetype/noto/NotoSansArabic-Bold.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    "/usr/local/share/fonts/NotoSansArabic-Regular.ttf",
    "/System/Library/Fonts/GeezaPro.ttc",
    "/System/Library/Fonts/SFArabic.ttf",
    "/System/Library/Fonts/Supplemental/Arial.ttf",
]

def get_font(size=46, bold=True):
    preferred = DEFAULT_FONTS if bold else [
        "/System/Library/Fonts/Supplemental/Arial.ttf",
        "/System/Library/Fonts/Helvetica.ttc",
        "/System/Library/Fonts/GeezaPro.ttc",
        "/System/Library/Fonts/SFArabic.ttf",
        "/System/Library/Fonts/Supplemental/Arial Bold.ttf",
    ]
    for f in preferred:
        if os.path.exists(f):
            try:
                return ImageFont.truetype(f, size)
            except Exception:
                continue
    return ImageFont.load_default()


def get_arabic_font(size=46):
    for font_path in ARABIC_FONTS:
        if os.path.exists(font_path):
            try:
                return ImageFont.truetype(font_path, size)
            except Exception:
                continue
    raise RuntimeError(
        "Arabic font not found. Set AIVIDEO_ARABIC_FONT to a licensed Arabic font "
        "(for example Noto Sans Arabic) before rendering Arabic content."
    )


def get_number_font(size=46, bold=True):
    number_fonts = [
        "/System/Library/Fonts/Supplemental/Arial Bold.ttf" if bold else "/System/Library/Fonts/Supplemental/Arial.ttf",
        "/System/Library/Fonts/Helvetica.ttc",
        "/Library/Fonts/Arial.ttf",
    ]
    for font_path in number_fonts:
        if os.path.exists(font_path):
            try:
                return ImageFont.truetype(font_path, size)
            except Exception:
                continue
    return get_font(size, bold)


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
1. "headline": A clear, accurate, concise opening title.
2. "point_1": Numbered point 1.
3. "point_2": Numbered point 2.
4. "point_3": Numbered point 3.
5. "caption": A relevant caption; do not invent a personal story or unsupported outcome.
6. "hashtags": 4-7 relevant general hashtags; do not claim they are trending.

Output ONLY valid JSON.
"""


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
                    "maxOutputTokens": 1024,
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
            print(f"[AI Warning] Gemini {model} failed: {e}")
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


def _normalize_ai_package(data):
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

    if not normalized["headline"]:
        return None

    try:
        score = int(round(float(data.get("score"))))
    except (TypeError, ValueError):
        score = None
    if score is None or not 0 <= score <= 100:
        fields = ("headline", "point_1", "point_2", "point_3", "caption", "hashtags")
        score = int(round(sum(bool(normalized[field]) for field in fields) / len(fields) * 100))
    normalized["score"] = score
    return normalized


def generate_ai_package(topic, niche="productivity", goal="followers", language="en", style="engaging", gemini_api_keys=None, user_prompt="", format_type="full_idea", target_country="Global"):
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

    for key in gemini_keys:
        try:
            data = _try_gemini_key(key, topic, niche, goal, language, user_prompt, style, format_type, target_country)
            if data:
                normalized = _normalize_ai_package(data)
                if normalized:
                    normalized["content_source"] = "gemini"
                    normalized["ai_model"] = data.get("ai_model")
                    return normalized
        except Exception as e:
            print(f"[AI Warning] Gemini key failed: {e}")

    if not gemini_keys:
        raise RuntimeError("Gemini API key is required. Add an active Gemini key before generating content.")
    raise RuntimeError("Gemini content generation failed for all configured keys. No local fallback is available.")

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


def tokenize_styled(text, accent_rgb, default_color=(255, 255, 255)):
    tokens = []
    parts = str(text or '').split('*')
    explicit_highlight = any(i % 2 == 1 and part.strip() for i, part in enumerate(parts))
    for i, part in enumerate(parts):
        color = accent_rgb if (i % 2 == 1) else default_color
        for word in part.split():
            tokens.append([word, color])
    if tokens and str(tokens[0][0])[:1].isdigit():
        tokens[0][1] = (249, 115, 22)
    if tokens and not explicit_highlight:
        first_content_index = 1 if str(tokens[0][0]).rstrip('.)،:-').isdigit() else 0
        if first_content_index < len(tokens):
            tokens[first_content_index][1] = accent_rgb
    return tokens


def _display_word(word, language):
    return shape_arabic_word(word) if contains_arabic(word) else word


def narration_text_from_package(package):
    parts = [str(package.get("headline", "")).strip()]
    for index in range(1, 4):
        point = str(package.get(f"point_{index}", "")).strip()
        point = re.sub(r"^\s*\d{1,2}[.)،:-]?\s*", "", point)
        if point:
            parts.append(point)
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


def render_reference_card(package, size=(1080, 1920), duration=6, template="classic", handle="", anti_border=True, language="en", accent_color="#fb7185", font_scale=100, include_branding=True, include_cta=True):
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

    scale = max(0.8, min(1.3, float(font_scale) / 100.0))
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

    # Determine headline text color per template
    if template == "clean_news":
        text_fill = (15, 23, 42)
    else:
        text_fill = (255, 255, 255)

    # 2. Main Headline Card
    headline_tokens = tokenize_styled(package.get("headline", ""), accent_rgb, text_fill)
    headline_lines = wrap_tokens(headline_tokens, font_head, card_w - 60, draw, language, font_number_head, font_arabic_head)
    head_h = (len(headline_lines) * head_line_height) + round(40 * scale)
    
    # Template styling
    if template == "clean_news":
        # White bold rectangular card with black text
        draw.rounded_rectangle([margin_x, cur_y, width - margin_x, cur_y + head_h], radius=16, fill=(248, 250, 252, 245), outline=(226, 232, 240, 255), width=2)
        text_fill = (15, 23, 42)
    elif template == "cinematic":
        # Frosted glass
        draw.rounded_rectangle([margin_x, cur_y, width - margin_x, cur_y + head_h], radius=28, fill=(15, 23, 42, 210), outline=(255, 255, 255, 70), width=3)
        text_fill = (255, 255, 255)
    else: # Classic dark card with highlight
        draw.rounded_rectangle([margin_x, cur_y, width - margin_x, cur_y + head_h], radius=24, fill=(15, 23, 42, 230), outline=(255, 255, 255, 40), width=2)
        text_fill = (255, 255, 255)

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
        p_tokens = tokenize_styled(point_text, accent_rgb, (255, 255, 255))
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

    # Keep the CTA for the closing beat instead of repeating it on every scene.
    if include_cta:
        pill_text = "Save this for later." if language == "en" else "احفظه وارجع له وقت الحاجة"
        display_pill = _display_word(pill_text, language)
        pill_font = font_arabic_footer if contains_arabic(pill_text) else font_footer
        bbox_pill = draw.textbbox((0, 0), display_pill, font=pill_font)
        pill_w = (bbox_pill[2] - bbox_pill[0]) + 40
        pill_x = (width - pill_w) // 2
        cur_y += 10
        draw.rounded_rectangle([pill_x, cur_y, pill_x + pill_w, cur_y + 44], radius=12, fill=(248, 250, 252, 230))
        draw.text((pill_x + 20, cur_y + 10), display_pill, font=pill_font, fill=(15, 23, 42))

    # 5. Anti-Border Warning Template (قوالب ضد تحذير الحدود)
    if anti_border:
        # Subtle semi-transparent guides on borders (safe zones for TikTok/Reels)
        # Top safe-zone (140px for platform header)
        draw.rectangle([0, 0, width, 140], fill=(0, 0, 0, 30))
        # Bottom safe-zone (280px for sound/like buttons/caption)
        draw.rectangle([0, height - 280, width, height], fill=(0, 0, 0, 30))

    img_np = np.array(img)
    return ImageClip(img_np).with_duration(duration) if hasattr(ImageClip, 'with_duration') else ImageClip(img_np).set_duration(duration)


def animate_reference_overlay(overlay, duration):
    """Reveal branding, headline, and point cards in reading order, with a restrained lift."""
    width, height = overlay.size
    base_mask = overlay.mask.get_frame(0).astype(np.float32) if overlay.mask is not None else np.ones((height, width), dtype=np.float32)
    feather = max(18, int(height * 0.025))

    mask = VideoClip(
        frame_function=lambda t: _staged_text_reveal_mask_frame(base_mask, t, feather),
        is_mask=True,
        duration=duration,
    )
    animated = overlay.with_mask(mask)

    def lift_position(time):
        progress = np.clip(float(time) / 0.42, 0.0, 1.0)
        eased_progress = progress * progress * (3.0 - 2.0 * progress)
        return (0, int(round(18 * (1.0 - eased_progress))))

    return animated.with_position(lift_position)


def apply_scene_transition(overlay, duration, fade_in=0.18, fade_out=0.24):
    """Add a short opacity envelope without changing the overlay's timing."""
    base_mask = overlay.mask
    if base_mask is None:
        return overlay

    def transition_mask(time):
        current = np.asarray(base_mask.get_frame(time), dtype=np.float32)
        fade_in_progress = min(max(float(time) / max(fade_in, 1e-6), 0.0), 1.0)
        fade_out_start = max(0.0, float(duration) - fade_out)
        fade_out_progress = min(max((float(time) - fade_out_start) / max(fade_out, 1e-6), 0.0), 1.0)
        envelope = (fade_in_progress * (1.0 - fade_out_progress))
        return np.clip(current * envelope, 0.0, 1.0)

    mask = VideoClip(frame_function=transition_mask, is_mask=True, duration=duration)
    return overlay.with_mask(mask)


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
    source = VideoFileClip(filepath)
    source_w, source_h = source.size
    target_w, target_h = resolution
    target_ratio = target_w / target_h

    if source_w / source_h > target_ratio:
        base_crop_h = source_h
        base_crop_w = max(1, int(source_h * target_ratio))
    else:
        base_crop_w = source_w
        base_crop_h = max(1, int(source_w / target_ratio))

    motion = motion if motion in {"static", "zoom_in", "pan"} else "static"

    def make_frame(time):
        frame = source.get_frame(min(max(float(time), 0.0), max(float(source.duration) - 1e-4, 0.0)))
        progress = min(max(float(time) / max(float(source.duration), 1e-6), 0.0), 1.0)
        if motion == "zoom_in":
            zoom = 1.0 + (0.08 * progress)
            center_offset = 0.0
        elif motion == "pan":
            zoom = 1.08
            center_offset = (progress - 0.5) * base_crop_w * 0.12
        else:
            zoom = 1.0
            center_offset = 0.0

        crop_w = min(source_w, max(1, int(base_crop_w / zoom)))
        crop_h = min(source_h, max(1, int(base_crop_h / zoom)))
        center_x = source_w / 2.0 + center_offset
        x1 = max(0, min(source_w - crop_w, int(round(center_x - crop_w / 2.0))))
        y1 = max(0, min(source_h - crop_h, int(round((source_h - crop_h) / 2.0))))
        image = Image.fromarray(np.asarray(frame, dtype=np.uint8))
        image = image.crop((x1, y1, x1 + crop_w, y1 + crop_h))
        image = image.resize((target_w, target_h), Image.Resampling.LANCZOS)
        return np.asarray(image)

    clip = VideoClip(frame_function=make_frame, duration=float(source.duration))
    if source.audio is not None:
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
    """Return a clear validation error for files that exist but are not readable videos."""
    path = str(filepath)
    if not os.path.isfile(path):
        return "Input video does not exist: " + path
    if os.path.getsize(path) <= 0:
        return "Input video is empty: " + path

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

def build_ultra_reel(args):
    resolution = (1080, 1920) if args.aspect_ratio == "9:16" else ((1080, 1080) if args.aspect_ratio == "1:1" else (1920, 1080))
    print(f"[Engine Ultra] Generating package for topic: '{args.topic}'")

    base_package = generate_ai_package(
        topic=args.topic,
        niche=args.niche,
        goal=args.goal,
        language=args.language,
        style=args.style,
            gemini_api_keys=getattr(args, 'gemini_api_keys', None),
        user_prompt=getattr(args, 'user_prompt', ''),
        format_type=getattr(args, 'format_type', 'full_idea'),
        target_country=getattr(args, 'target_country', 'Global')
    )

    custom_pts = args.custom_points if getattr(args, 'custom_points', None) else []
    if getattr(args, 'custom_headline', None) or custom_pts:
        package = {
            "headline": args.custom_headline or base_package.get("headline", args.topic),
            "point_1": custom_pts[0] if len(custom_pts) > 0 else base_package.get("point_1", ""),
            "point_2": custom_pts[1] if len(custom_pts) > 1 else base_package.get("point_2", ""),
            "point_3": custom_pts[2] if len(custom_pts) > 2 else base_package.get("point_3", ""),
            "caption": base_package.get("caption", ""),
            "hashtags": base_package.get("hashtags", ""),
            "score": base_package.get("score"),
            "content_source": base_package.get("content_source", "gemini"),
        }
    else:
        package = base_package

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

        per_dur = render_duration / len(valid_clips)
        processed = [process_motion_clip(f, per_dur, resolution=resolution, motion=args.camera_motion) for f in valid_clips]
        concatenated = concatenate_videoclips(processed, method="compose")

        scene_texts = [str(package.get("headline", "")).strip()]
        for index in range(1, 4):
            point = str(package.get(f"point_{index}", "")).strip()
            point = re.sub(r"^\s*\d{1,2}[.)،:-]?\s*", "", point)
            if point:
                scene_texts.append(f"{index}. {point}")
        scene_texts = [text for text in scene_texts if text]
        if not scene_texts:
            scene_texts = [str(args.topic).strip()]

        if voiceover_enabled:
            weights = [max(1, len(text.split())) for text in scene_texts]
        else:
            weights = [1.35] + [1.0] * (len(scene_texts) - 1)
        total_weight = sum(weights) or 1
        elapsed = 0.0
        for index, scene_text in enumerate(scene_texts):
            scene_duration = render_duration - elapsed if index == len(scene_texts) - 1 else render_duration * weights[index] / total_weight
            scene_package = {
                "headline": scene_text,
                "point_1": "",
                "point_2": "",
                "point_3": "",
            }
            overlay = render_reference_card(
                package=scene_package,
                size=resolution,
                duration=scene_duration,
                template=args.template,
                handle=args.handle,
                anti_border=args.anti_border,
                language=args.language,
                accent_color=getattr(args, 'accent_color', '#fb7185'),
                font_scale=getattr(args, 'font_scale', 100),
                include_branding=(index == 0),
                include_cta=(index == len(scene_texts) - 1),
            )
            overlay = animate_reference_overlay(overlay, scene_duration)
            transition_profile = {
                "cinematic": (0.28, 0.36),
                "clean_news": (0.12, 0.18),
                "quote_card": (0.22, 0.30),
                "classic": (0.18, 0.24),
            }.get(args.template, (0.18, 0.24))
            overlay = apply_scene_transition(overlay, scene_duration, *transition_profile)
            overlay = overlay.with_start(elapsed) if hasattr(overlay, "with_start") else overlay.set_start(elapsed)
            overlays.append(overlay)
            elapsed += scene_duration

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

        if args.music and os.path.exists(args.music):
            bg_audio = AudioFileClip(args.music)
            auxiliary_audio.append(bg_audio)
            if bg_audio.duration < render_duration:
                repeats = int(render_duration / max(float(bg_audio.duration), 0.1)) + 1
                bg_audio = concatenate_audioclips([bg_audio] * repeats)
                auxiliary_audio.append(bg_audio)
            bg_audio = bg_audio.subclipped(0, render_duration) if hasattr(bg_audio, 'subclipped') else bg_audio.subclip(0, render_duration)
            music_level = 0.12 if voiceover_enabled else (0.2 if source_audio is not None else 0.45)
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
        final_video.write_videofile(
            args.output,
            fps=25,
            codec='libx264',
            audio_codec='aac',
            bitrate='2800k',
            threads=4,
            logger=None
        )
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
        "script": package.get("headline", "") + "\n" + package.get("point_1", "") + "\n" + package.get("point_2", "") + "\n" + package.get("point_3", ""),
        "caption": package.get("caption"),
        "hashtags": package.get("hashtags"),
        "score": package.get("score", 95),
        "content_source": package.get("content_source", "gemini"),
        "duration": render_duration,
        "language": args.language,
        "template": args.template,
        "niche": args.niche,
        "goal": args.goal
    }

def main():
    parser = argparse.ArgumentParser(description="AI Smart Reels Studio Engine v3.0 Ultra")
    parser.add_argument("--input", "-i", nargs="+", required=True)
    parser.add_argument("--topic", "-t", required=True)
    parser.add_argument("--user-prompt", default="")
    parser.add_argument("--output", "-o", default="output_ultra.mp4")
    parser.add_argument("--duration", "-d", type=float, default=6.0)
    parser.add_argument("--language", "-l", default="en", choices=["en", "ar"])
    parser.add_argument("--niche", default="productivity")
    parser.add_argument("--goal", default="followers")
    parser.add_argument("--target-country", default="Global", choices=["US", "UK", "Canada", "Australia", "Global"])
    parser.add_argument("--style", default="engaging")
    parser.add_argument("--format-type", default="full_idea", choices=["full_idea", "quick_tip", "list", "story"])
    parser.add_argument("--template", default="classic", choices=["classic", "clean_news", "cinematic", "quote_card"])
    parser.add_argument("--handle", default="")
    parser.add_argument("--aspect-ratio", default="9:16", choices=["9:16", "1:1", "16:9"])
    parser.add_argument("--camera-motion", default="zoom_in", choices=["static", "zoom_in", "pan"])
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
    parser.add_argument("--json", action="store_true")

    args = parser.parse_args()

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
