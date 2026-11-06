#!/usr/bin/env python3
"""Generate original, silent motion backgrounds for the local Reels app.

No downloaded footage, network calls, or external generation services are used.
Run with the project's virtualenv:
    ../venv/bin/python python_engine/generate_backgrounds.py
"""

from __future__ import annotations

import argparse
import json
import subprocess
from pathlib import Path

import imageio_ffmpeg
import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_OUTPUT_DIR = ROOT / "laravel-app/storage/app/public/backgrounds"
WIDTH, HEIGHT = 360, 640
OUTPUT_WIDTH, OUTPUT_HEIGHT = 1080, 1920
FPS = 24
DURATION = 6
FRAME_COUNT = FPS * DURATION

# Every visual is an original procedural graphic designed to stay subdued
# behind the reel's central text cards and platform-safe zones.
BACKGROUNDS = [
    {
        "id": "finance_grid",
        "file": "finance_grid.mp4",
        "poster": "finance_grid.jpg",
        "colors": [(5, 13, 27), (9, 27, 43), (7, 17, 32)],
        "glows": [
            {"color": (18, 160, 148), "cx": 0.14, "cy": 0.17, "rx": 0.55, "ry": 0.24, "strength": 0.38, "dx": 0.07, "dy": 0.035, "offset": 0.0},
            {"color": (235, 143, 63), "cx": 0.83, "cy": 0.86, "rx": 0.48, "ry": 0.22, "strength": 0.24, "dx": 0.06, "dy": 0.04, "offset": 2.2},
        ],
        "pattern": "grid",
        "seed": 17,
    },
    {
        "id": "neon_city",
        "file": "neon_city.mp4",
        "poster": "neon_city.jpg",
        "colors": [(4, 8, 20), (9, 17, 35), (13, 10, 29)],
        "glows": [
            {"color": (25, 189, 222), "cx": 0.08, "cy": 0.23, "rx": 0.35, "ry": 0.34, "strength": 0.36, "dx": 0.08, "dy": 0.04, "offset": 0.3},
            {"color": (215, 45, 154), "cx": 0.93, "cy": 0.70, "rx": 0.34, "ry": 0.38, "strength": 0.32, "dx": 0.07, "dy": 0.045, "offset": 2.4},
        ],
        "pattern": "neon",
        "seed": 23,
    },
    {
        "id": "ocean_dusk",
        "file": "ocean_dusk.mp4",
        "poster": "ocean_dusk.jpg",
        "colors": [(4, 19, 34), (5, 42, 54), (7, 22, 42)],
        "glows": [
            {"color": (21, 150, 153), "cx": 0.80, "cy": 0.20, "rx": 0.58, "ry": 0.28, "strength": 0.31, "dx": 0.10, "dy": 0.035, "offset": 0.9},
            {"color": (35, 86, 137), "cx": 0.12, "cy": 0.88, "rx": 0.64, "ry": 0.24, "strength": 0.34, "dx": 0.08, "dy": 0.04, "offset": 2.7},
        ],
        "pattern": "ocean",
        "seed": 31,
    },
    {
        "id": "warm_editorial",
        "file": "warm_editorial.mp4",
        "poster": "warm_editorial.jpg",
        "colors": [(25, 13, 23), (49, 27, 31), (24, 14, 22)],
        "glows": [
            {"color": (196, 105, 54), "cx": 0.88, "cy": 0.20, "rx": 0.54, "ry": 0.26, "strength": 0.34, "dx": 0.06, "dy": 0.035, "offset": 1.1},
            {"color": (125, 60, 50), "cx": 0.10, "cy": 0.82, "rx": 0.54, "ry": 0.29, "strength": 0.24, "dx": 0.08, "dy": 0.045, "offset": 3.4},
        ],
        "pattern": "editorial",
        "seed": 47,
    },
    {
        "id": "aurora_glass",
        "file": "aurora_glass.mp4",
        "poster": "aurora_glass.jpg",
        "colors": [(7, 9, 27), (15, 18, 48), (7, 14, 34)],
        "glows": [
            {"color": (91, 67, 191), "cx": 0.20, "cy": 0.16, "rx": 0.66, "ry": 0.25, "strength": 0.43, "dx": 0.09, "dy": 0.06, "offset": 0.2},
            {"color": (28, 145, 169), "cx": 0.86, "cy": 0.83, "rx": 0.60, "ry": 0.27, "strength": 0.30, "dx": 0.10, "dy": 0.05, "offset": 2.1},
        ],
        "pattern": "aurora",
        "seed": 59,
    },
    {
        "id": "clean_studio",
        "file": "clean_studio.mp4",
        "poster": "clean_studio.jpg",
        "colors": [(9, 16, 27), (21, 31, 45), (9, 17, 29)],
        "glows": [
            {"color": (84, 127, 155), "cx": 0.50, "cy": 0.08, "rx": 0.74, "ry": 0.26, "strength": 0.28, "dx": 0.05, "dy": 0.025, "offset": 0.6},
            {"color": (36, 91, 104), "cx": 0.12, "cy": 0.91, "rx": 0.60, "ry": 0.22, "strength": 0.23, "dx": 0.08, "dy": 0.035, "offset": 3.0},
        ],
        "pattern": "studio",
        "seed": 71,
    },
]


def smoothstep(value: np.ndarray) -> np.ndarray:
    value = np.clip(value, 0.0, 1.0)
    return value * value * (3.0 - 2.0 * value)


def mix_color(image: np.ndarray, color: tuple[int, int, int], alpha: np.ndarray | float) -> np.ndarray:
    amount = np.asarray(alpha, dtype=np.float32)
    if amount.ndim == 0:
        amount = np.full((HEIGHT, WIDTH), float(amount), dtype=np.float32)
    amount = np.clip(amount, 0.0, 1.0)[..., None]
    target = np.asarray(color, dtype=np.float32)
    return image * (1.0 - amount) + target * amount


def vertical_gradient(colors: list[tuple[int, int, int]], y: np.ndarray) -> np.ndarray:
    top, middle, bottom = (np.asarray(color, dtype=np.float32) for color in colors)
    upper = top[None, None, :] * (1.0 - smoothstep(y * 2.0)[..., None]) + middle[None, None, :] * smoothstep(y * 2.0)[..., None]
    lower_t = smoothstep((y - 0.5) * 2.0)[..., None]
    lower = middle[None, None, :] * (1.0 - lower_t) + bottom[None, None, :] * lower_t
    return np.where((y < 0.5)[..., None], upper, lower).astype(np.float32)


def make_frame(spec: dict, phase: float, x: np.ndarray, y: np.ndarray, noise: np.ndarray) -> np.ndarray:
    image = vertical_gradient(spec["colors"], y)

    for glow in spec["glows"]:
        angle = phase + glow["offset"]
        cx = glow["cx"] + glow["dx"] * np.sin(angle)
        cy = glow["cy"] + glow["dy"] * np.cos(angle)
        distance = ((x - cx) / glow["rx"]) ** 2 + ((y - cy) / glow["ry"]) ** 2
        alpha = np.exp(-distance * 2.1) * glow["strength"]
        image = mix_color(image, glow["color"], alpha)

    pattern = spec["pattern"]
    if pattern == "grid":
        x_mod = np.mod(x + 0.012 * np.sin(phase), 0.085)
        y_mod = np.mod(y, 0.072)
        dx = np.minimum(x_mod, 0.085 - x_mod)
        dy = np.minimum(y_mod, 0.072 - y_mod)
        grid = (np.exp(-((dx / 0.0015) ** 2)) + np.exp(-((dy / 0.0016) ** 2))) * 0.045
        image = mix_color(image, (51, 114, 132), grid)
        # Faint points at selected intersections add a finance/data motif without cluttering the text area.
        point_x = np.minimum(np.mod(x - 0.01, 0.34), 0.34 - np.mod(x - 0.01, 0.34))
        point_y = np.minimum(np.mod(y - 0.01, 0.29), 0.29 - np.mod(y - 0.01, 0.29))
        nodes = np.exp(-((point_x / 0.006) ** 2 + (point_y / 0.006) ** 2)) * 0.13
        image = mix_color(image, (103, 197, 182), nodes)
    elif pattern == "neon":
        # Perspective-like building edges, strongest toward the sides of the frame.
        edge_mask = np.clip((np.abs(x - 0.5) - 0.12) * 2.4, 0.0, 1.0)
        positions = (0.08, 0.21, 0.78, 0.93)
        for index, position in enumerate(positions):
            shift = 0.018 * np.sin(phase + index)
            line = np.exp(-(((x - position - shift) / 0.0032) ** 2)) * edge_mask * (0.14 if index % 2 else 0.20)
            color = (24, 194, 226) if index % 2 == 0 else (204, 56, 166)
            image = mix_color(image, color, line)
        floor = np.exp(-(((y - 0.83) - 0.018 * np.sin(x * 8.0 + phase)) / 0.004) ** 2) * 0.15
        image = mix_color(image, (55, 134, 180), floor)
    elif pattern == "ocean":
        wave_a = np.sin(2.0 * np.pi * (y * 3.4 + 0.09 * np.sin(x * 7.0 + phase)))
        wave_b = np.sin(2.0 * np.pi * (y * 5.2 - 0.07 * np.sin(x * 5.0 - phase * 0.7) + 0.24))
        mask_a = np.exp(-(((wave_a - 0.32) / 0.13) ** 2)) * 0.11
        mask_b = np.exp(-(((wave_b + 0.28) / 0.15) ** 2)) * 0.065
        image = mix_color(image, (68, 174, 174), mask_a)
        image = mix_color(image, (43, 100, 156), mask_b)
    elif pattern == "editorial":
        # Quiet, warm editorial rings live near the outer corners, away from copy.
        radius = np.sqrt(((x - 0.88) / 0.72) ** 2 + ((y - 0.18) / 0.34) ** 2)
        rings = np.exp(-(((radius - (0.76 + 0.035 * np.sin(phase))) / 0.012) ** 2)) * 0.13
        second = np.exp(-(((radius - 0.91) / 0.009) ** 2)) * 0.07
        image = mix_color(image, (226, 164, 95), rings + second)
        dust = np.exp(-(((x - (0.11 + 0.02 * np.sin(phase))) / 0.22) ** 2 + ((y - 0.79) / 0.15) ** 2)) * 0.14
        image = mix_color(image, (154, 84, 63), dust)
    elif pattern == "aurora":
        center_a = 0.17 + 0.055 * np.sin(x * 6.3 + phase)
        center_b = 0.86 + 0.045 * np.sin(x * 5.2 - phase * 0.8 + 1.0)
        band_a = np.exp(-(((y - center_a) / 0.045) ** 2)) * 0.18
        band_b = np.exp(-(((y - center_b) / 0.06) ** 2)) * 0.12
        image = mix_color(image, (144, 109, 238), band_a)
        image = mix_color(image, (54, 185, 195), band_b)
    elif pattern == "studio":
        # Architectural hairlines and a restrained edge glow frame the central type area.
        x_mod = np.mod(x, 0.18)
        dx = np.minimum(x_mod, 0.18 - x_mod)
        lines = np.exp(-((dx / 0.0018) ** 2)) * np.clip((np.abs(x - 0.5) - 0.28) * 1.6, 0.0, 1.0) * 0.09
        image = mix_color(image, (128, 165, 184), lines)
        horizon = np.exp(-(((y - 0.12) / 0.004) ** 2)) * 0.09
        image = mix_color(image, (128, 165, 184), horizon)

    # Subtle edge falloff and stable fine grain reduce banding without competing with text.
    edge = np.maximum(np.abs(x - 0.5) * 2.0, np.abs(y - 0.5) * 2.0)
    image *= (1.0 - 0.105 * edge ** 2)[..., None]
    image += noise[..., None]
    return np.clip(image, 0, 255).astype(np.uint8)


def render_video(spec: dict, output_dir: Path, ffmpeg: str, x: np.ndarray, y: np.ndarray, noise: np.ndarray) -> None:
    output_path = output_dir / spec["file"]
    command = [
        ffmpeg, "-hide_banner", "-loglevel", "error", "-y",
        "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{WIDTH}x{HEIGHT}", "-r", str(FPS), "-i", "-",
        "-an", "-vf", f"scale={OUTPUT_WIDTH}:{OUTPUT_HEIGHT}:flags=lanczos,format=yuv420p",
        "-frames:v", str(FRAME_COUNT), "-c:v", "libx264", "-preset", "fast", "-crf", "21",
        "-movflags", "+faststart", "-pix_fmt", "yuv420p", str(output_path),
    ]
    process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    assert process.stdin is not None
    try:
        for frame_number in range(FRAME_COUNT):
            phase = 2.0 * np.pi * frame_number / (FRAME_COUNT - 1)
            frame = make_frame(spec, phase, x, y, noise)
            process.stdin.write(frame.tobytes())
        process.stdin.close()
        error = process.stderr.read().decode("utf-8", errors="replace") if process.stderr else ""
        return_code = process.wait()
        if return_code != 0:
            raise RuntimeError(f"FFmpeg failed for {output_path.name}: {error.strip()}")
    except Exception:
        if process.poll() is None:
            process.kill()
        raise


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR)
    args = parser.parse_args()
    output_dir = args.output_dir.expanduser().resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()
    x_grid, y_grid = np.meshgrid(
        np.linspace(0.0, 1.0, WIDTH, dtype=np.float32),
        np.linspace(0.0, 1.0, HEIGHT, dtype=np.float32),
    )

    for spec in BACKGROUNDS:
        rng = np.random.default_rng(spec["seed"])
        grain = rng.normal(0.0, 0.75, size=(HEIGHT, WIDTH)).astype(np.float32)
        first_frame = make_frame(spec, 0.0, x_grid, y_grid, grain)
        poster = Image.fromarray(first_frame).resize((540, 960), Image.Resampling.LANCZOS)
        poster.save(output_dir / spec["poster"], format="JPEG", quality=88, optimize=True, progressive=True)
        render_video(spec, output_dir, ffmpeg, x_grid, y_grid, grain)
        size_mb = (output_dir / spec["file"]).stat().st_size / (1024 * 1024)
        print(f"Created {spec['file']} ({OUTPUT_WIDTH}x{OUTPUT_HEIGHT}, {DURATION}s, {FPS} fps, silent, {size_mb:.2f} MB)")

    manifest = {
        "source": "Original procedural motion graphics generated locally by python_engine/generate_backgrounds.py",
        "rights": "Project-created assets; no third-party footage, music, network calls, or paid generation services.",
        "format": {"width": OUTPUT_WIDTH, "height": OUTPUT_HEIGHT, "duration_seconds": DURATION, "fps": FPS, "video_codec": "H.264", "audio": "none"},
        "assets": [{"id": item["id"], "file": item["file"], "poster": item["poster"]} for item in BACKGROUNDS],
    }
    (output_dir / "catalog.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {output_dir / 'catalog.json'}")


if __name__ == "__main__":
    main()
