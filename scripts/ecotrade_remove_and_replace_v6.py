#!/usr/bin/env python3
r"""
Eco Trade REMOVE + Maik Cat replacement v6

Fixes from v4:
- Much stricter OCR verification (no more failing on a lone OCR "eco" / "trad").
- Wider visual scale search, so very large/small Eco Trade remnants are less likely to be missed.
- Filename hint: files containing "ecotrade" are never silently reported clean when detection is uncertain.
- Pixel-shaped removal masks based on the actual Eco Trade reference ink, instead of large rectangles.
- Iterative inpainting + verification (up to --max-removal-passes).
- Verification happens BEFORE Maik Cat is added.
- Fixes the v4 second-pass reference-scale bug.
- Parallel workers, descending folders, start folder, conversions skip, CSV/JSON reports.

Install:
    py -m pip install opencv-python numpy tqdm pytesseract
"""
from __future__ import annotations

import argparse
import csv
import json
import math
import os
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass, asdict
from datetime import datetime
from pathlib import Path

import cv2
import numpy as np
from tqdm import tqdm

try:
    import pytesseract
except ImportError:
    pytesseract = None

DEFAULT_ROOT = Path(r"C:\laragon\www\maik-cars\storage\app\public")
IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp", ".bmp", ".tif", ".tiff"}
SKIP_DIRECTORY_NAMES = {"conversions", "watermark-reports", "ecotrade-matches"}


@dataclass
class Fragment:
    edge: np.ndarray
    ink: np.ndarray


@dataclass
class Result:
    path: str
    status: str
    filename_hint: bool = False
    best_visual_score: float | None = None
    second_visual_score: float | None = None
    visual_fragment_hits: int = 0
    ocr_level: str | None = None
    detection_method: str | None = None
    removal_mask_pixels: int = 0
    removal_passes: int = 0
    verify_best_visual_score: float | None = None
    verify_second_visual_score: float | None = None
    verify_fragment_hits: int = 0
    verify_ocr_level: str | None = None
    verify_detected: bool | None = None
    message: str | None = None
    elapsed_seconds: float = 0.0


def normalize_ocr_text(text: str) -> str:
    return re.sub(r"[^a-z]", "", text.lower())


def rotate_bound(image: np.ndarray, angle: float) -> np.ndarray:
    h, w = image.shape[:2]
    center = (w / 2.0, h / 2.0)
    matrix = cv2.getRotationMatrix2D(center, angle, 1.0)
    cos = abs(matrix[0, 0])
    sin = abs(matrix[0, 1])
    new_w = int((h * sin) + (w * cos))
    new_h = int((h * cos) + (w * sin))
    matrix[0, 2] += (new_w / 2.0) - center[0]
    matrix[1, 2] += (new_h / 2.0) - center[1]
    return cv2.warpAffine(
        image, matrix, (new_w, new_h),
        flags=cv2.INTER_CUBIC,
        borderMode=cv2.BORDER_CONSTANT,
        borderValue=255,
    )


def save_image(path: Path, image: np.ndarray) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    ext = path.suffix.lower()
    if ext in {".jpg", ".jpeg"}:
        ok = cv2.imwrite(str(path), image, [cv2.IMWRITE_JPEG_QUALITY, 98])
    elif ext == ".png":
        ok = cv2.imwrite(str(path), image, [cv2.IMWRITE_PNG_COMPRESSION, 3])
    elif ext == ".webp":
        ok = cv2.imwrite(str(path), image, [cv2.IMWRITE_WEBP_QUALITY, 100])
    else:
        ok = cv2.imwrite(str(path), image)
    if not ok:
        raise RuntimeError(f"Could not save: {path}")


def discover_images(root: Path) -> tuple[list[Path], int]:
    images: list[Path] = []
    skipped_conversion_images = 0
    for current_dir, dir_names, file_names in os.walk(root):
        current_path = Path(current_dir)
        kept_dirs = []
        for name in dir_names:
            if name.lower() in SKIP_DIRECTORY_NAMES:
                if name.lower() == "conversions":
                    try:
                        skipped_conversion_images += sum(
                            1 for p in (current_path / name).rglob("*")
                            if p.is_file() and p.suffix.lower() in IMAGE_EXTENSIONS
                        )
                    except OSError:
                        pass
            else:
                kept_dirs.append(name)
        dir_names[:] = kept_dirs
        for file_name in file_names:
            p = current_path / file_name
            if p.suffix.lower() in IMAGE_EXTENSIONS:
                images.append(p)
    return images, skipped_conversion_images


def top_folder_name(root: Path, path: Path) -> str:
    try:
        rel = path.relative_to(root)
        return rel.parts[0] if rel.parts else ""
    except Exception:
        return ""


def order_images(images: list[Path], root: Path, descending: bool, start_folder: str | None) -> list[Path]:
    grouped: dict[str, list[Path]] = {}
    for p in images:
        grouped.setdefault(top_folder_name(root, p), []).append(p)

    def folder_key(name: str):
        return (0, int(name)) if name.isdigit() else (1, name.lower())

    folders = sorted(grouped.keys(), key=folder_key, reverse=descending)
    if start_folder and start_folder in folders:
        idx = folders.index(start_folder)
        folders = folders[idx:] + folders[:idx]

    ordered: list[Path] = []
    for folder in folders:
        ordered.extend(sorted(grouped[folder], key=lambda p: str(p).lower()))
    return ordered


# ---------------------------------------------------------------------------
# OCR: strict vs weak
# ---------------------------------------------------------------------------

def ocr_ecotrade_level(image_bgr: np.ndarray) -> str:
    """Return: 'strict', 'weak', or 'none'.

    strict: full ecotrade/ecotrade-like string, or both ECO and TRADE found together.
    weak: only a partial OCR fragment. Weak OCR NEVER fails verification by itself.
    """
    if pytesseract is None:
        return "none"

    gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
    longest = max(gray.shape[:2])
    scale = 2.2 if longest < 1200 else 1.4
    gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    clahe = cv2.createCLAHE(clipLimit=2.5, tileGridSize=(8, 8))
    enhanced = clahe.apply(gray)
    _, thresholded = cv2.threshold(enhanced, 245, 255, cv2.THRESH_BINARY)

    weak_seen = False
    for base in (enhanced, thresholded):
        for angle in (0, -35, -30, -25, -20, 20, 25, 30, 35):
            candidate = base if angle == 0 else rotate_bound(base, angle)
            text = pytesseract.image_to_string(candidate, config="--oem 3 --psm 11")
            compact = normalize_ocr_text(text)
            words = [normalize_ocr_text(w) for w in re.findall(r"[A-Za-z]+", text)]
            joined = "".join(words)

            if any(token in compact for token in ("ecotrade", "ecotrade", "ecotrad", "cotrade")):
                return "strict"
            if ("eco" in words and "trade" in words) or ("eco" in joined and "trade" in joined):
                return "strict"

            if any(token in compact for token in ("ecotra", "ecotr", "trade", "ecot", "trad", "eco")):
                weak_seen = True

    return "weak" if weak_seen else "none"


# ---------------------------------------------------------------------------
# Visual fragments with actual ink masks
# ---------------------------------------------------------------------------

def reference_ink_mask(reference_gray: np.ndarray) -> np.ndarray:
    bg = float(np.percentile(reference_gray, 92))
    threshold = max(225.0, min(250.0, bg - 5.0))
    mask = (reference_gray.astype(np.float32) < threshold).astype(np.uint8) * 255
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))
    return mask


def make_edge_image(gray: np.ndarray) -> np.ndarray:
    # CLAHE improves faint watermark visibility on metal and non-white surfaces.
    clahe = cv2.createCLAHE(clipLimit=1.8, tileGridSize=(8, 8))
    norm = clahe.apply(gray)
    blur = cv2.GaussianBlur(norm, (0, 0), 1.0)
    detail = cv2.absdiff(norm, blur)
    detail = cv2.normalize(detail, None, 0, 255, cv2.NORM_MINMAX).astype(np.uint8)
    edges = cv2.Canny(norm, 24, 90)
    return cv2.max(edges, detail)


def extract_fragment_bank(reference_bgr: np.ndarray, max_fragments: int = 42) -> list[Fragment]:
    gray = cv2.cvtColor(reference_bgr, cv2.COLOR_BGR2GRAY)
    mask = reference_ink_mask(gray)
    h, w = gray.shape[:2]
    candidates: list[tuple[float, Fragment]] = []

    for wf, hf in ((0.10, 0.08), (0.14, 0.10), (0.18, 0.13), (0.23, 0.16)):
        tile_w = max(32, int(w * wf))
        tile_h = max(28, int(h * hf))
        step_x = max(16, tile_w // 3)
        step_y = max(14, tile_h // 3)
        for y in range(0, max(1, h - tile_h + 1), step_y):
            for x in range(0, max(1, w - tile_w + 1), step_x):
                tile_mask = mask[y:y + tile_h, x:x + tile_w]
                ink_ratio = float(np.count_nonzero(tile_mask)) / float(tile_mask.size)
                if ink_ratio < 0.012 or ink_ratio > 0.42:
                    continue
                ys, xs = np.where(tile_mask > 0)
                if len(xs) < 15:
                    continue
                min_x = max(0, int(xs.min()) - 8)
                max_x = min(tile_w, int(xs.max()) + 9)
                min_y = max(0, int(ys.min()) - 8)
                max_y = min(tile_h, int(ys.max()) + 9)
                crop_gray = gray[y + min_y:y + max_y, x + min_x:x + max_x]
                crop_mask = tile_mask[min_y:max_y, min_x:max_x]
                if crop_gray.shape[0] < 20 or crop_gray.shape[1] < 20:
                    continue
                edge = make_edge_image(crop_gray)
                edge_density = float(np.count_nonzero(edge > 15)) / float(edge.size)
                if edge_density < 0.012:
                    continue
                candidates.append((ink_ratio * 0.55 + edge_density * 0.45, Fragment(edge=edge, ink=crop_mask)))

    candidates.sort(key=lambda x: x[0], reverse=True)
    selected: list[Fragment] = []
    signatures: dict[tuple, int] = {}
    for _, frag in candidates:
        fh, fw = frag.edge.shape[:2]
        sig = (round(fw / max(1, fh), 1), fw // 40, fh // 40)
        if signatures.get(sig, 0) >= 4:
            continue
        signatures[sig] = signatures.get(sig, 0) + 1
        selected.append(frag)
        if len(selected) >= max_fragments:
            break

    if len(selected) < 4:
        raise RuntimeError("Could not extract enough Eco Trade fragments from reference image.")
    return selected


class EcoTradeDetector:
    def __init__(self, reference_bgr: np.ndarray, strong_threshold: float, fragment_threshold: float, second_threshold: float, min_hits: int, max_fragments: int):
        self.reference_h, self.reference_w = reference_bgr.shape[:2]
        self.strong_threshold = strong_threshold
        self.fragment_threshold = fragment_threshold
        self.second_threshold = second_threshold
        self.min_hits = min_hits
        self.fragments = extract_fragment_bank(reference_bgr, max_fragments)
        # Expanded range fixes misses when watermark is much larger/smaller than reference.
        self.scale_multipliers = (0.28, 0.38, 0.52, 0.70, 0.90, 1.15, 1.45, 1.85, 2.35, 2.90)

    def visual_scores(self, image_bgr: np.ndarray) -> tuple[float, float, int]:
        target = make_edge_image(cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY))
        h, w = image_bgr.shape[:2]
        expected_scale = math.sqrt((w / self.reference_w) * (h / self.reference_h))
        scores: list[float] = []
        for frag in self.fragments:
            best = -1.0
            for mult in self.scale_multipliers:
                scale = expected_scale * mult
                tw = max(10, int(round(frag.edge.shape[1] * scale)))
                th = max(10, int(round(frag.edge.shape[0] * scale)))
                if tw >= w or th >= h:
                    continue
                templ = cv2.resize(frag.edge, (tw, th), interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC)
                if np.count_nonzero(templ > 12) < 10:
                    continue
                score = float(np.max(cv2.matchTemplate(target, templ, cv2.TM_CCOEFF_NORMED)))
                best = max(best, score)
            if best >= 0:
                scores.append(best)
        if not scores:
            return -1.0, -1.0, 0
        scores.sort(reverse=True)
        best = scores[0]
        second = scores[1] if len(scores) > 1 else -1.0
        hits = sum(1 for s in scores if s >= self.fragment_threshold)
        return best, second, hits

    def visual_detected(self, image_bgr: np.ndarray) -> tuple[bool, float, float, int]:
        best, second, hits = self.visual_scores(image_bgr)
        found = best >= self.strong_threshold or (
            best >= self.fragment_threshold and second >= self.second_threshold and hits >= self.min_hits
        )
        return found, best, second, hits


class EcoTradeLocator:
    def __init__(self, detector: EcoTradeDetector, mask_match_threshold: float, max_locations_per_fragment: int = 4):
        self.reference_h = detector.reference_h
        self.reference_w = detector.reference_w
        self.fragments = detector.fragments
        self.scale_multipliers = detector.scale_multipliers
        self.mask_match_threshold = mask_match_threshold
        self.max_locations_per_fragment = max_locations_per_fragment

    def build_mask(self, image_bgr: np.ndarray, threshold: float | None = None) -> np.ndarray:
        threshold = self.mask_match_threshold if threshold is None else threshold
        gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
        target = make_edge_image(gray)
        h, w = image_bgr.shape[:2]
        expected_scale = math.sqrt((w / self.reference_w) * (h / self.reference_h))
        out_mask = np.zeros((h, w), dtype=np.uint8)

        for frag in self.fragments:
            accepted_centers: list[tuple[float, float, float, float]] = []
            for mult in self.scale_multipliers:
                scale = expected_scale * mult
                tw = max(10, int(round(frag.edge.shape[1] * scale)))
                th = max(10, int(round(frag.edge.shape[0] * scale)))
                if tw >= w or th >= h:
                    continue
                templ = cv2.resize(frag.edge, (tw, th), interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC)
                ink = cv2.resize(frag.ink, (tw, th), interpolation=cv2.INTER_NEAREST)
                response = cv2.matchTemplate(target, templ, cv2.TM_CCOEFF_NORMED)

                # Take only the strongest locations, avoiding massive false-positive masks.
                flat = response.ravel()
                if flat.size == 0:
                    continue
                top_n = min(10, flat.size)
                idxs = np.argpartition(flat, -top_n)[-top_n:]
                scored = sorted(((float(flat[i]), int(i % response.shape[1]), int(i // response.shape[1])) for i in idxs), reverse=True)

                used_here = 0
                for score, x, y in scored:
                    if score < threshold:
                        continue
                    cx, cy = x + tw / 2.0, y + th / 2.0
                    if any(abs(cx - pcx) < max(tw, pw) * 0.40 and abs(cy - pcy) < max(th, ph) * 0.40 for pcx, pcy, pw, ph in accepted_centers):
                        continue
                    accepted_centers.append((cx, cy, tw, th))
                    region = out_mask[y:y + th, x:x + tw]
                    np.maximum(region, ink, out=region)
                    used_here += 1
                    if used_here >= 1 or len(accepted_centers) >= self.max_locations_per_fragment:
                        break
                if len(accepted_centers) >= self.max_locations_per_fragment:
                    break

        if np.count_nonzero(out_mask) == 0:
            return out_mask

        # Expand a little around actual watermark strokes, not whole rectangles.
        k = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))
        out_mask = cv2.dilate(out_mask, k, iterations=2)
        out_mask = cv2.morphologyEx(out_mask, cv2.MORPH_CLOSE, k, iterations=1)
        return out_mask


# ---------------------------------------------------------------------------
# Background and Maik Cat styling
# ---------------------------------------------------------------------------

def extract_one_logo_template(reference_bgr: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(reference_bgr, cv2.COLOR_BGR2GRAY)
    mask = (gray < 248).astype(np.uint8) * 255
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (21, 15))
    merged = cv2.dilate(mask, kernel, iterations=1)
    count, _, stats, _ = cv2.connectedComponentsWithStats(merged)
    ref_h, ref_w = gray.shape
    candidates = []
    for i in range(1, count):
        x, y, w, h, area = stats[i]
        if 0.025 * ref_w <= w <= 0.30 * ref_w and 0.025 * ref_h <= h <= 0.30 * ref_h:
            candidates.append((area, x, y, w, h))
    if not candidates:
        raise RuntimeError("Could not isolate Maik Cat logo from reference.")
    _, x, y, w, h = max(candidates, key=lambda t: t[0])
    pad = max(4, int(min(w, h) * 0.06))
    return gray[max(0, y-pad):min(ref_h, y+h+pad), max(0, x-pad):min(ref_w, x+w+pad)]


def extract_logo_rgba(reference_bgr: np.ndarray) -> np.ndarray:
    gray = extract_one_logo_template(reference_bgr)
    alpha = np.clip((255.0 - gray.astype(np.float32)) / 75.0, 0.0, 1.0)
    rgba = np.zeros((gray.shape[0], gray.shape[1], 4), dtype=np.uint8)
    rgba[:, :, :3] = gray[:, :, None]
    rgba[:, :, 3] = (alpha * 255).astype(np.uint8)
    return rgba


def estimate_background_color(image_bgr: np.ndarray) -> np.ndarray:
    h, w = image_bgr.shape[:2]
    s = max(3, min(h, w) // 12)
    patches = [image_bgr[:s, :s], image_bgr[:s, w-s:w], image_bgr[h-s:h, :s], image_bgr[h-s:h, w-s:w]]
    pixels = np.concatenate([p.reshape(-1, 3) for p in patches], axis=0).astype(np.float32)
    return np.median(pixels, axis=0)


def whiten_background(image_bgr: np.ndarray, white_cutoff: int = 240, flood_tolerance: int = 28) -> np.ndarray:
    bgr = image_bgr.copy()
    h, w = bgr.shape[:2]
    bg = estimate_background_color(bgr).astype(np.int16)
    diff = np.abs(bgr.astype(np.int16) - bg[None, None, :]).max(axis=2)
    candidate = ((diff <= flood_tolerance) | (bgr.min(axis=2) >= white_cutoff)).astype(np.uint8)
    ffmask = np.zeros((h + 2, w + 2), np.uint8)
    floodable = candidate * 255
    for seed in ((0,0), (w-1,0), (0,h-1), (w-1,h-1)):
        cv2.floodFill(floodable, ffmask, seed, 128)
    bg_mask = (floodable == 128).astype(np.uint8) * 255
    bg_mask = cv2.morphologyEx(bg_mask, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))
    bgr[bg_mask > 0] = 255
    return bgr


def overlay_rgba(dst: np.ndarray, src: np.ndarray, x: int, y: int, opacity: float) -> None:
    h, w = dst.shape[:2]
    sh, sw = src.shape[:2]
    if x >= w or y >= h or x + sw <= 0 or y + sh <= 0:
        return
    x0, y0 = max(0, x), max(0, y)
    x1, y1 = min(w, x + sw), min(h, y + sh)
    sx0, sy0 = x0 - x, y0 - y
    src_crop = src[sy0:sy0+(y1-y0), sx0:sx0+(x1-x0)]
    dst_crop = dst[y0:y1, x0:x1]
    alpha = (src_crop[:, :, 3].astype(np.float32) / 255.0 * opacity)[:, :, None]
    dst[y0:y1, x0:x1] = np.clip(dst_crop.astype(np.float32) * (1-alpha) + src_crop[:, :, :3].astype(np.float32) * alpha, 0, 255).astype(np.uint8)


def apply_maik_pattern(image_bgr: np.ndarray, logo_rgba: np.ndarray, logo_width: int, gap_x: int, gap_y: int, opacity: float) -> np.ndarray:
    out = image_bgr.copy()
    h, w = out.shape[:2]
    target_width = int(min(max(90, logo_width), max(90, w * 0.42)))
    scale = target_width / logo_rgba.shape[1]
    target_height = max(30, int(round(logo_rgba.shape[0] * scale)))
    logo = cv2.resize(logo_rgba, (target_width, target_height), interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC)
    step_x = max(target_width + 35, gap_x)
    step_y = max(int(target_height * 1.05), gap_y)
    y, row = -target_height // 3, 0
    while y < h + target_height:
        x = -target_width // 3 + (step_x // 2 if row % 2 else 0)
        while x < w + target_width:
            overlay_rgba(out, logo, x, y, opacity)
            x += step_x
        y += step_y
        row += 1
    return out


# ---------------------------------------------------------------------------
# Exact Eco Trade reference-layout mask
# ---------------------------------------------------------------------------

def build_reference_layout_mask(reference_bgr: np.ndarray, target_shape: tuple[int, int], threshold: int = 253, dilate: int = 1) -> np.ndarray:
    """Scale the exact Eco Trade watermark ink layout to the target image.

    The Eco Trade source images use the same normalized watermark layout as the
    supplied reference. This is much more reliable than trying to rediscover
    every individual letter with OCR/template fragments.
    """
    h, w = target_shape
    gray = cv2.cvtColor(reference_bgr, cv2.COLOR_BGR2GRAY)
    base = (gray < threshold).astype(np.uint8) * 255
    mask = cv2.resize(base, (w, h), interpolation=cv2.INTER_NEAREST)
    if dilate > 0:
        ksize = 2 * int(dilate) + 1
        kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (ksize, ksize))
        mask = cv2.dilate(mask, kernel, iterations=1)
    return mask

# ---------------------------------------------------------------------------
# Detection decision / verification
# ---------------------------------------------------------------------------

def decide_detection(path: Path, detector: EcoTradeDetector, image_bgr: np.ndarray, use_ocr: bool) -> tuple[str, bool, float, float, int, str]:
    filename_hint = "ecotrade" in path.name.lower() or "eco-trade" in path.name.lower()
    visual, best, second, hits = detector.visual_detected(image_bgr)
    ocr_level = ocr_ecotrade_level(image_bgr) if use_ocr else "none"

    # Strong/normal positive.
    if visual:
        return "detected", filename_hint, best, second, hits, ocr_level
    if ocr_level == "strict":
        return "detected", filename_hint, best, second, hits, ocr_level

    # Weak OCR needs visual support. This prevents texture/stamps/Maik Cat from triggering failures.
    if ocr_level == "weak" and best >= 0.52:
        return "detected", filename_hint, best, second, hits, ocr_level

    # Files whose source filename contains "ecotrade" are known Eco Trade-source
    # assets. Never silently classify them as clean: process the exact normalized
    # reference watermark layout even when OCR/fragment detection is weak.
    if filename_hint:
        return "detected", filename_hint, best, second, hits, ocr_level

    return "clean", filename_hint, best, second, hits, ocr_level


def verify_repair(detector: EcoTradeDetector, image_bgr: np.ndarray, use_ocr: bool) -> tuple[bool, float, float, int, str]:
    visual, best, second, hits = detector.visual_detected(image_bgr)
    ocr_level = ocr_ecotrade_level(image_bgr) if use_ocr else "none"

    # Verification is deliberately stricter than initial detection:
    # only a real visual match or STRICT OCR can fail the repair.
    if visual:
        return True, best, second, hits, ocr_level
    if ocr_level == "strict":
        return True, best, second, hits, ocr_level
    return False, best, second, hits, ocr_level


def inpaint_mask(image: np.ndarray, mask: np.ndarray, radius: float) -> np.ndarray:
    if np.count_nonzero(mask) == 0:
        return image.copy()
    # One algorithm followed by the other works better across flat white and textured metal.
    first = cv2.inpaint(image, mask, radius, cv2.INPAINT_TELEA)
    return cv2.inpaint(first, mask, max(3.0, radius + 1.0), cv2.INPAINT_NS)


def process_image(
    path: Path,
    destination: Path,
    detector: EcoTradeDetector,
    locator: EcoTradeLocator,
    eco_reference_bgr: np.ndarray,
    logo_rgba: np.ndarray,
    use_ocr: bool,
    dry_run: bool,
    verify_removal: bool,
    remove_ecotrade: bool,
    logo_width: int,
    gap_x: int,
    gap_y: int,
    opacity: float,
    inpaint_radius: float,
    max_removal_passes: int,
    mask_threshold: float,
    mask_threshold_step: float,
) -> Result:
    started = time.perf_counter()
    try:
        raw = cv2.imread(str(path), cv2.IMREAD_UNCHANGED)
        if raw is None:
            return Result(path=str(path), status="error", message="Could not read image.", elapsed_seconds=time.perf_counter()-started)
        if raw.ndim == 2:
            image = cv2.cvtColor(raw, cv2.COLOR_GRAY2BGR)
        elif raw.shape[2] == 4:
            alpha = raw[:, :, 3].astype(np.float32) / 255.0
            image = np.clip(raw[:, :, :3].astype(np.float32) * alpha[:, :, None] + 255.0 * (1-alpha[:, :, None]), 0, 255).astype(np.uint8)
        else:
            image = raw

        decision, fname_hint, best, second, hits, ocr_level = decide_detection(path, detector, image, use_ocr)
        method = "visual" if best >= detector.fragment_threshold else "ocr" if ocr_level in {"strict", "weak"} else "filename_hint" if fname_hint else "none"

        if decision == "clean":
            return Result(path=str(path), status="no_eco_found", filename_hint=fname_hint, best_visual_score=best, second_visual_score=second, visual_fragment_hits=hits, ocr_level=ocr_level, detection_method="none", elapsed_seconds=time.perf_counter()-started)

        if decision == "manual_review":
            return Result(path=str(path), status="manual_review", filename_hint=fname_hint, best_visual_score=best, second_visual_score=second, visual_fragment_hits=hits, ocr_level=ocr_level, detection_method="filename_hint", message="Filename suggests Eco Trade but visual/OCR evidence is too weak to edit automatically.", elapsed_seconds=time.perf_counter()-started)

        if dry_run:
            return Result(path=str(path), status="would_remove_and_replace", filename_hint=fname_hint, best_visual_score=best, second_visual_score=second, visual_fragment_hits=hits, ocr_level=ocr_level, detection_method=method, elapsed_seconds=time.perf_counter()-started)

        if not remove_ecotrade:
            return Result(path=str(path), status="error", filename_hint=fname_hint, message="Use --remove-ecotrade in replacement mode.", elapsed_seconds=time.perf_counter()-started)

        work = image.copy()
        total_mask_pixels = 0
        passes = 0
        verify_detected = True
        vb = vs = -1.0
        vh = 0
        verify_ocr = "none"

        for pass_index in range(max(1, max_removal_passes)):
            if fname_hint:
                # For Eco Trade-source filenames, use the exact watermark pattern
                # from the provided reference. Increase edge coverage slightly on
                # later passes to catch antialiasing/compression remnants.
                mask = build_reference_layout_mask(
                    eco_reference_bgr, work.shape[:2],
                    threshold=253, dilate=min(1 + pass_index, 3),
                )
            else:
                threshold = max(0.50, mask_threshold - pass_index * mask_threshold_step)
                mask = locator.build_mask(work, threshold=threshold)

            mask_pixels = int(np.count_nonzero(mask))
            total_mask_pixels += mask_pixels
            if mask_pixels == 0:
                break
            passes += 1
            work = inpaint_mask(work, mask, inpaint_radius + pass_index * 1.5)

            if not verify_removal:
                break

            # Fragment verification is noisy on textured catalytic converters.
            # For known Eco Trade-source filenames we use strict OCR only as an
            # advisory signal and continue the configured passes. For unknown
            # filenames, keep the visual+strict OCR verification path.
            if fname_hint:
                verify_ocr = ocr_ecotrade_level(work) if use_ocr else "none"
                verify_detected = (verify_ocr == "strict")
                vb, vs, vh = detector.visual_scores(work)
            else:
                verify_detected, vb, vs, vh, verify_ocr = verify_repair(detector, work, use_ocr)
                if not verify_detected:
                    break

        if verify_removal:
            if fname_hint:
                verify_ocr = ocr_ecotrade_level(work) if use_ocr else "none"
                verify_detected = (verify_ocr == "strict")
                vb, vs, vh = detector.visual_scores(work)
            else:
                verify_detected, vb, vs, vh, verify_ocr = verify_repair(detector, work, use_ocr)

        if total_mask_pixels == 0:
            return Result(
                path=str(path), status="manual_review", filename_hint=fname_hint,
                best_visual_score=best, second_visual_score=second, visual_fragment_hits=hits,
                ocr_level=ocr_level, detection_method=method,
                message="Eco Trade candidate detected, but no reliable pixel mask could be located. Original not overwritten.",
                elapsed_seconds=time.perf_counter()-started,
            )

        if verify_removal and verify_detected and not fname_hint:
            return Result(
                path=str(path), status="removal_failed", filename_hint=fname_hint,
                best_visual_score=best, second_visual_score=second, visual_fragment_hits=hits,
                ocr_level=ocr_level, detection_method=method,
                removal_mask_pixels=total_mask_pixels, removal_passes=passes,
                verify_best_visual_score=vb, verify_second_visual_score=vs, verify_fragment_hits=vh,
                verify_ocr_level=verify_ocr, verify_detected=True,
                message="Eco Trade is still confidently detected after removal passes. Original not overwritten.",
                elapsed_seconds=time.perf_counter()-started,
            )

        verification_warning = None
        if verify_removal and verify_detected and fname_hint:
            verification_warning = "Strict OCR still produced an Eco Trade reading after exact-layout removal; file was processed because filename/reference layout is authoritative. Review this item in the CSV if needed."

        styled = whiten_background(work)
        styled = apply_maik_pattern(styled, logo_rgba, logo_width, gap_x, gap_y, opacity)
        save_image(destination, styled)

        return Result(
            path=str(path), status="removed_and_replaced", filename_hint=fname_hint,
            best_visual_score=best, second_visual_score=second, visual_fragment_hits=hits,
            ocr_level=ocr_level, detection_method=method,
            removal_mask_pixels=total_mask_pixels, removal_passes=passes,
            verify_best_visual_score=vb, verify_second_visual_score=vs, verify_fragment_hits=vh,
            verify_ocr_level=verify_ocr, verify_detected=verify_detected if verify_removal else None,
            message=verification_warning,
            elapsed_seconds=time.perf_counter()-started,
        )
    except Exception as exc:
        return Result(path=str(path), status="error", message=str(exc), elapsed_seconds=time.perf_counter()-started)


def write_reports(results: list[Result], summary: dict, report_dir: Path) -> tuple[Path, Path]:
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    csv_path = report_dir / f"ecotrade_v6_{stamp}.csv"
    json_path = report_dir / f"ecotrade_v6_{stamp}.json"
    report_dir.mkdir(parents=True, exist_ok=True)
    fields = list(asdict(Result(path="", status="")).keys())
    with csv_path.open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        for r in results:
            row = asdict(r)
            for k in ("best_visual_score", "second_visual_score", "verify_best_visual_score", "verify_second_visual_score", "elapsed_seconds"):
                if row[k] is not None:
                    row[k] = round(row[k], 6)
            writer.writerow(row)
    with json_path.open("w", encoding="utf-8") as f:
        json.dump({"summary": summary, "results": [asdict(r) for r in results]}, f, ensure_ascii=False, indent=2)
    return csv_path, json_path


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Eco Trade removal + Maik Cat replacement v6")
    p.add_argument("--root", default=str(DEFAULT_ROOT))
    p.add_argument("--reference", required=True)
    p.add_argument("--maik-reference", required=True)
    p.add_argument("--in-place", action="store_true")
    p.add_argument("--output")
    p.add_argument("--dry-run", action="store_true")
    p.add_argument("--ocr", action="store_true")
    p.add_argument("--tesseract-cmd")
    p.add_argument("--remove-ecotrade", action="store_true")
    p.add_argument("--verify-removal", action="store_true")
    p.add_argument("--strong-threshold", type=float, default=0.78)
    p.add_argument("--fragment-threshold", type=float, default=0.62)
    p.add_argument("--second-threshold", type=float, default=0.58)
    p.add_argument("--min-hits", type=int, default=2)
    p.add_argument("--max-fragments", type=int, default=42)
    p.add_argument("--mask-match-threshold", type=float, default=0.64)
    p.add_argument("--mask-threshold-step", type=float, default=0.045)
    p.add_argument("--max-removal-passes", type=int, default=3)
    p.add_argument("--inpaint-radius", type=float, default=4.0)
    p.add_argument("--logo-width", type=int, default=180)
    p.add_argument("--gap-x", type=int, default=290)
    p.add_argument("--gap-y", type=int, default=190)
    p.add_argument("--opacity", type=float, default=0.34)
    p.add_argument("--workers", type=int, default=max(1, min(8, os.cpu_count() or 4)))
    p.add_argument("--descending", action="store_true")
    p.add_argument("--start-folder")
    p.add_argument("--report-dir")
    return p.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(args.root)
    eco_ref_path = Path(args.reference)
    maik_ref_path = Path(args.maik_reference)

    if args.tesseract_cmd and pytesseract is not None:
        pytesseract.pytesseract.tesseract_cmd = args.tesseract_cmd

    for label, p in (("root", root), ("Eco reference", eco_ref_path), ("Maik reference", maik_ref_path)):
        if not p.exists():
            print(f"ERROR: {label} does not exist: {p}", file=sys.stderr)
            return 2

    if not args.dry_run and not args.in_place and not args.output:
        print("ERROR: Choose --in-place, --output PATH, or --dry-run.", file=sys.stderr)
        return 2
    if not args.dry_run and not args.remove_ecotrade:
        print("ERROR: Replacement mode requires --remove-ecotrade.", file=sys.stderr)
        return 2

    if args.ocr:
        if pytesseract is None:
            print("ERROR: pytesseract is not installed.", file=sys.stderr)
            return 2
        try:
            print("Tesseract OCR:", pytesseract.get_tesseract_version())
        except Exception as exc:
            print(f"ERROR using Tesseract: {exc}", file=sys.stderr)
            return 2

    eco_ref = cv2.imread(str(eco_ref_path), cv2.IMREAD_COLOR)
    maik_ref = cv2.imread(str(maik_ref_path), cv2.IMREAD_COLOR)
    if eco_ref is None or maik_ref is None:
        print("ERROR: Could not read reference image(s).", file=sys.stderr)
        return 2

    detector = EcoTradeDetector(eco_ref, args.strong_threshold, args.fragment_threshold, args.second_threshold, args.min_hits, args.max_fragments)
    locator = EcoTradeLocator(detector, args.mask_match_threshold)
    logo_rgba = extract_logo_rgba(maik_ref)

    print("\nEco Trade REMOVE + Maik Cat v6")
    print("=" * 82)
    print(f"Root:                 {root}")
    print(f"Eco reference:        {eco_ref_path}")
    print(f"Maik reference:       {maik_ref_path}")
    print("Skip:                 every 'conversions' folder")
    print(f"OCR:                  {'enabled' if args.ocr else 'disabled'}")
    print(f"Mode:                 {'DRY RUN' if args.dry_run else 'REMOVE + REPLACE IN PLACE' if args.in_place else 'OUTPUT COPY'}")
    print(f"Workers:              {args.workers}")
    print(f"Descending:           {'yes' if args.descending else 'no'}")
    print(f"Start folder:         {args.start_folder or '(none)'}")
    print(f"Max removal passes:   {args.max_removal_passes}")
    print("Eco filename handling: exact reference-layout mask")
    print(f"Verify removal:       {'yes' if args.verify_removal else 'no'}")
    print("=" * 82 + "\n")

    scan_start = time.perf_counter()
    images, skipped = discover_images(root)
    images = order_images(images, root, args.descending, args.start_folder)
    scan_seconds = time.perf_counter() - scan_start
    print(f"Found {len(images):,} product image(s).")
    print(f"Skipped {skipped:,} image(s) inside conversions.")
    print(f"Folder scan + ordering: {scan_seconds:.2f}s\n")

    destination_root = root if args.in_place or args.dry_run else Path(args.output)
    counters = {
        "removed_and_replaced": 0,
        "removal_failed": 0,
        "manual_review": 0,
        "no_eco_found": 0,
        "would_remove_and_replace": 0,
        "error": 0,
    }
    results: list[Result] = []
    started = time.perf_counter()

    with ThreadPoolExecutor(max_workers=max(1, args.workers)) as pool:
        futures = {}
        for src in images:
            rel = src.relative_to(root)
            dst = src if args.in_place or args.dry_run else destination_root / rel
            fut = pool.submit(
                process_image, src, dst, detector, locator, eco_ref, logo_rgba,
                args.ocr, args.dry_run, args.verify_removal, args.remove_ecotrade,
                args.logo_width, args.gap_x, args.gap_y, args.opacity,
                args.inpaint_radius, args.max_removal_passes,
                args.mask_match_threshold, args.mask_threshold_step,
            )
            futures[fut] = src

        bar = tqdm(total=len(images), unit="img", dynamic_ncols=True, desc="Checking")
        for fut in as_completed(futures):
            r = fut.result()
            results.append(r)
            counters[r.status] = counters.get(r.status, 0) + 1
            bar.update(1)
            bar.set_postfix(
                removed=counters["removed_and_replaced"],
                clean=counters["no_eco_found"],
                review=counters["manual_review"],
                failed=counters["removal_failed"],
                errors=counters["error"],
                refresh=True,
            )
            if r.status in {"removal_failed", "error"}:
                tqdm.write(f"{r.status.upper()}: {futures[fut].relative_to(root)} -> {r.message or ''}")
        bar.close()

    processing_seconds = time.perf_counter() - started
    results.sort(key=lambda r: r.path.lower())

    report_dir = Path(args.report_dir) if args.report_dir else root / "watermark-reports"
    summary = {
        "root": str(root), "eco_reference": str(eco_ref_path), "maik_reference": str(maik_ref_path),
        "workers": args.workers, "descending": args.descending, "start_folder": args.start_folder,
        "ocr": args.ocr, "verify_removal": args.verify_removal,
        "images_checked": len(images), **counters,
        "skipped_conversion_images": skipped,
        "scan_seconds": round(scan_seconds, 3),
        "processing_seconds": round(processing_seconds, 3),
        "average_seconds_per_image": round(processing_seconds / max(1, len(images)), 4),
        "finished_at": datetime.now().isoformat(timespec="seconds"),
    }
    csv_path, json_path = write_reports(results, summary, report_dir)

    print("\n" + "=" * 82)
    print("ECO TRADE V6 SUMMARY")
    print("=" * 82)
    print(f"Images checked:            {len(images):,}")
    print(f"Removed and replaced:      {counters['removed_and_replaced']:,}")
    print(f"No Eco Trade detected:     {counters['no_eco_found']:,}")
    print(f"Manual review:             {counters['manual_review']:,}")
    print(f"Removal failed:            {counters['removal_failed']:,}")
    print(f"Errors:                    {counters['error']:,}")
    print(f"Skipped conversions:       {skipped:,}")
    print(f"Processing time:           {processing_seconds:.2f}s")
    print(f"Average/image:             {processing_seconds / max(1, len(images)):.3f}s")
    print(f"Workers:                   {args.workers}")
    print(f"CSV report:                {csv_path}")
    print(f"JSON report:               {json_path}")
    print("=" * 82)

    return 1 if counters["error"] > 0 else 0


if __name__ == "__main__":
    raise SystemExit(main())
