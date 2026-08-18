#!/usr/bin/env python3
r"""
Eco Trade detector + replacement with Maik Cat style (parallel version)

New features:
- Parallel processing with --workers
- Can start from a specific top-level folder with --start-folder
- Can process in descending folder order with --descending
- Keeps the same capabilities:
  - skips "conversions"
  - detects Eco Trade visually + OCR fallback
  - replaces matched files in place with white background + Maik Cat watermark
  - reports CSV / JSON
"""
from __future__ import annotations

import argparse, csv, json, math, os, re, sys, time
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
class Result:
    path: str
    status: str
    best_visual_score: float | None = None
    second_visual_score: float | None = None
    visual_fragment_hits: int = 0
    detection_method: str | None = None
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
    return cv2.warpAffine(image, matrix, (new_w, new_h), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_CONSTANT, borderValue=255)


def ocr_contains_ecotrade(image_bgr: np.ndarray) -> bool:
    if pytesseract is None:
        raise RuntimeError("pytesseract is not installed.")
    gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
    longest = max(gray.shape[:2])
    scale = 2.4 if longest < 1200 else 1.5
    gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    clahe = cv2.createCLAHE(clipLimit=2.5, tileGridSize=(8, 8))
    enhanced = clahe.apply(gray)
    _, thresholded = cv2.threshold(enhanced, 245, 255, cv2.THRESH_BINARY)
    adaptive = cv2.adaptiveThreshold(enhanced, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 41, 11)
    partial_markers = ("ecotrade", "ecotra", "cotrade", "ecotr", "trade", "trad", "ecot", "eco")
    for base in (enhanced, thresholded, adaptive):
        for angle in (0, -35, -30, -25, -20, -15, 15, 20, 25, 30, 35):
            candidate = base if angle == 0 else rotate_bound(base, angle)
            text = pytesseract.image_to_string(candidate, config="--oem 3 --psm 11")
            compact = normalize_ocr_text(text)
            if any(marker in compact for marker in partial_markers):
                return True
            words = re.findall(r"[a-z]+", text.lower())
            if ("eco" in words and "trade" in words) or "ecotrade" in "".join(words):
                return True
    return False


def reference_ink_mask(reference_gray: np.ndarray) -> np.ndarray:
    bg = float(np.percentile(reference_gray, 92))
    threshold = max(225.0, min(250.0, bg - 5.0))
    mask = (reference_gray.astype(np.float32) < threshold).astype(np.uint8) * 255
    return cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))


def make_edge_image(gray: np.ndarray) -> np.ndarray:
    blur = cv2.GaussianBlur(gray, (0, 0), 1.0)
    detail = cv2.absdiff(gray, blur)
    detail = cv2.normalize(detail, None, 0, 255, cv2.NORM_MINMAX).astype(np.uint8)
    edges = cv2.Canny(gray, 30, 100)
    return cv2.max(edges, detail)


def extract_fragment_bank(reference_bgr: np.ndarray, max_fragments: int = 42) -> list[np.ndarray]:
    gray = cv2.cvtColor(reference_bgr, cv2.COLOR_BGR2GRAY)
    mask = reference_ink_mask(gray)
    h, w = gray.shape[:2]
    tile_specs = ((0.12, 0.10), (0.16, 0.12), (0.20, 0.14))
    candidates = []
    for wf, hf in tile_specs:
        tile_w = max(36, int(w * wf))
        tile_h = max(32, int(h * hf))
        step_x = max(20, tile_w // 3)
        step_y = max(18, tile_h // 3)
        for y in range(0, max(1, h - tile_h + 1), step_y):
            for x in range(0, max(1, w - tile_w + 1), step_x):
                tile_mask = mask[y:y + tile_h, x:x + tile_w]
                ink_ratio = float(np.count_nonzero(tile_mask)) / float(tile_mask.size)
                if ink_ratio < 0.018 or ink_ratio > 0.40:
                    continue
                ys, xs = np.where(tile_mask > 0)
                if len(xs) < 20:
                    continue
                min_x = max(0, int(xs.min()) - 10)
                max_x = min(tile_w, int(xs.max()) + 11)
                min_y = max(0, int(ys.min()) - 10)
                max_y = min(tile_h, int(ys.max()) + 11)
                crop = gray[y + min_y:y + max_y, x + min_x:x + max_x]
                if crop.shape[0] < 25 or crop.shape[1] < 25:
                    continue
                edge_crop = make_edge_image(crop)
                edge_density = float(np.count_nonzero(edge_crop > 18)) / float(edge_crop.size)
                if edge_density < 0.018:
                    continue
                candidates.append((ink_ratio * 0.55 + edge_density * 0.45, edge_crop))
    candidates.sort(key=lambda item: item[0], reverse=True)
    selected, signatures = [], set()
    for _, fragment in candidates:
        fh, fw = fragment.shape[:2]
        signature = (round(fw / max(1, fh), 1), int(fw / 40), int(fh / 40))
        if signature in signatures:
            dup_count = sum(1 for existing in selected if round(existing.shape[1] / max(1, existing.shape[0]), 1) == signature[0] and int(existing.shape[1] / 40) == signature[1] and int(existing.shape[0] / 40) == signature[2])
            if dup_count >= 3:
                continue
        selected.append(fragment)
        signatures.add(signature)
        if len(selected) >= max_fragments:
            break
    if len(selected) < 4:
        raise RuntimeError("Could not extract enough Eco Trade fragments from the reference image.")
    return selected


class EcoTradeDetector:
    def __init__(self, reference_bgr: np.ndarray, strong_threshold: float, fragment_threshold: float, second_threshold: float, min_hits: int, max_fragments: int):
        self.reference_h, self.reference_w = reference_bgr.shape[:2]
        self.strong_threshold = strong_threshold
        self.fragment_threshold = fragment_threshold
        self.second_threshold = second_threshold
        self.min_hits = min_hits
        self.fragments = extract_fragment_bank(reference_bgr, max_fragments=max_fragments)

    def visual_scores(self, image_bgr: np.ndarray) -> tuple[float, float, int]:
        target_gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
        target_edge = make_edge_image(target_gray)
        h, w = image_bgr.shape[:2]
        expected_scale = math.sqrt((w / self.reference_w) * (h / self.reference_h))
        scale_multipliers = (0.55, 0.70, 0.85, 1.00, 1.15, 1.30, 1.50)
        scores = []
        for fragment in self.fragments:
            best_for_fragment = -1.0
            for multiplier in scale_multipliers:
                scale = expected_scale * multiplier
                tw = max(12, int(round(fragment.shape[1] * scale)))
                th = max(12, int(round(fragment.shape[0] * scale)))
                if tw >= w or th >= h or tw < 10 or th < 10:
                    continue
                resized = cv2.resize(fragment, (tw, th), interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC)
                if np.count_nonzero(resized > 15) < 12:
                    continue
                score = float(np.max(cv2.matchTemplate(target_edge, resized, cv2.TM_CCOEFF_NORMED)))
                if score > best_for_fragment:
                    best_for_fragment = score
            if best_for_fragment >= 0:
                scores.append(best_for_fragment)
        if not scores:
            return -1.0, -1.0, 0
        scores.sort(reverse=True)
        best = scores[0]
        second = scores[1] if len(scores) > 1 else -1.0
        hits = sum(1 for s in scores if s >= self.fragment_threshold)
        return best, second, hits

    def visual_detected(self, image_bgr: np.ndarray) -> tuple[bool, float, float, int]:
        best, second, hits = self.visual_scores(image_bgr)
        if best >= self.strong_threshold:
            return True, best, second, hits
        if best >= self.fragment_threshold and second >= self.second_threshold and hits >= self.min_hits:
            return True, best, second, hits
        return False, best, second, hits


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
        raise RuntimeError("Could not isolate a Maik Cat logo from the supplied reference image.")
    _, x, y, w, h = max(candidates, key=lambda item: item[0])
    pad = max(4, int(min(w, h) * 0.06))
    x0, y0 = max(0, x - pad), max(0, y - pad)
    x1, y1 = min(ref_w, x + w + pad), min(ref_h, y + h + pad)
    return gray[y0:y1, x0:x1]


def extract_logo_rgba(reference_bgr: np.ndarray) -> np.ndarray:
    gray_logo = extract_one_logo_template(reference_bgr)
    alpha = np.clip((255.0 - gray_logo.astype(np.float32)) / 75.0, 0.0, 1.0)
    rgba = np.zeros((gray_logo.shape[0], gray_logo.shape[1], 4), dtype=np.uint8)
    rgba[:, :, 0] = gray_logo
    rgba[:, :, 1] = gray_logo
    rgba[:, :, 2] = gray_logo
    rgba[:, :, 3] = (alpha * 255).astype(np.uint8)
    return rgba


def estimate_background_color(image_bgr: np.ndarray) -> np.ndarray:
    h, w = image_bgr.shape[:2]
    s = max(3, min(h, w) // 12)
    patches = [image_bgr[:s, :s], image_bgr[:s, w - s:w], image_bgr[h - s:h, :s], image_bgr[h - s:h, w - s:w]]
    pixels = np.concatenate([p.reshape(-1, 3) for p in patches], axis=0).astype(np.float32)
    return np.median(pixels, axis=0)


def whiten_background(image: np.ndarray, white_cutoff: int = 240, flood_tolerance: int = 28) -> np.ndarray:
    if image.ndim == 2:
        bgr = cv2.cvtColor(image, cv2.COLOR_GRAY2BGR)
    elif image.shape[2] == 4:
        bgr = image[:, :, :3].copy()
        alpha = image[:, :, 3].astype(np.float32) / 255.0
        white = np.full_like(bgr, 255)
        comp = (bgr.astype(np.float32) * alpha[:, :, None] + white.astype(np.float32) * (1.0 - alpha[:, :, None]))
        return np.clip(comp, 0, 255).astype(np.uint8)
    else:
        bgr = image.copy()
    h, w = bgr.shape[:2]
    bg_color = estimate_background_color(bgr).astype(np.int16)
    diff = np.abs(bgr.astype(np.int16) - bg_color[None, None, :]).max(axis=2)
    candidate_bg = ((diff <= flood_tolerance) | (bgr.min(axis=2) >= white_cutoff)).astype(np.uint8)
    flood_seed_mask = np.zeros((h + 2, w + 2), np.uint8)
    floodable = (candidate_bg * 255).copy()
    cv2.floodFill(floodable, flood_seed_mask, (0, 0), 128)
    cv2.floodFill(floodable, flood_seed_mask, (w - 1, 0), 128)
    cv2.floodFill(floodable, flood_seed_mask, (0, h - 1), 128)
    cv2.floodFill(floodable, flood_seed_mask, (w - 1, h - 1), 128)
    bg_mask = (floodable == 128).astype(np.uint8) * 255
    bg_mask = cv2.morphologyEx(bg_mask, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))
    out = bgr.copy()
    out[bg_mask > 0] = 255
    return out


def overlay_rgba(dst_bgr: np.ndarray, src_rgba: np.ndarray, x: int, y: int, opacity: float) -> None:
    h, w = dst_bgr.shape[:2]
    sh, sw = src_rgba.shape[:2]
    if x >= w or y >= h or x + sw <= 0 or y + sh <= 0:
        return
    x0, y0 = max(0, x), max(0, y)
    x1, y1 = min(w, x + sw), min(h, y + sh)
    sx0, sy0 = x0 - x, y0 - y
    sx1, sy1 = sx0 + (x1 - x0), sy0 + (y1 - y0)
    src_crop = src_rgba[sy0:sy1, sx0:sx1]
    dst_crop = dst_bgr[y0:y1, x0:x1]
    alpha = (src_crop[:, :, 3].astype(np.float32) / 255.0) * opacity
    alpha = alpha[:, :, None]
    blended = dst_crop.astype(np.float32) * (1.0 - alpha) + src_crop[:, :, :3].astype(np.float32) * alpha
    dst_bgr[y0:y1, x0:x1] = np.clip(blended, 0, 255).astype(np.uint8)


def apply_maik_pattern(image_bgr: np.ndarray, logo_rgba: np.ndarray, logo_width: int, gap_x: int, gap_y: int, opacity: float) -> np.ndarray:
    out = image_bgr.copy()
    h, w = out.shape[:2]
    target_width = int(min(max(90, logo_width), max(90, w * 0.42)))
    scale = target_width / logo_rgba.shape[1]
    target_height = max(30, int(round(logo_rgba.shape[0] * scale)))
    resized_logo = cv2.resize(logo_rgba, (target_width, target_height), interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC)
    step_x = max(target_width + 35, gap_x)
    step_y = max(int(target_height * 1.05), gap_y)
    stagger = step_x // 2
    y = -target_height // 3
    row = 0
    while y < h + target_height:
        x = -target_width // 3 + (stagger if row % 2 else 0)
        while x < w + target_width:
            overlay_rgba(out, resized_logo, x, y, opacity)
            x += step_x
        y += step_y
        row += 1
    return out


def save_image(path: Path, image: np.ndarray) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    ext = path.suffix.lower()
    if ext in {'.jpg', '.jpeg'}:
        ok = cv2.imwrite(str(path), image, [cv2.IMWRITE_JPEG_QUALITY, 98])
    elif ext == '.png':
        ok = cv2.imwrite(str(path), image, [cv2.IMWRITE_PNG_COMPRESSION, 3])
    elif ext == '.webp':
        ok = cv2.imwrite(str(path), image, [cv2.IMWRITE_WEBP_QUALITY, 100])
    else:
        ok = cv2.imwrite(str(path), image)
    if not ok:
        raise RuntimeError(f'Could not save: {path}')


def discover_images(root: Path) -> tuple[list[Path], int]:
    images = []
    skipped_conversion_images = 0
    for current_dir, dir_names, file_names in os.walk(root):
        current_path = Path(current_dir)
        kept_dirs = []
        for directory_name in dir_names:
            if directory_name.lower() in SKIP_DIRECTORY_NAMES:
                if directory_name.lower() == 'conversions':
                    try:
                        skipped_conversion_images += sum(1 for p in (current_path / directory_name).rglob('*') if p.is_file() and p.suffix.lower() in IMAGE_EXTENSIONS)
                    except OSError:
                        pass
            else:
                kept_dirs.append(directory_name)
        dir_names[:] = kept_dirs
        for file_name in file_names:
            path = current_path / file_name
            if path.suffix.lower() in IMAGE_EXTENSIONS:
                images.append(path)
    return images, skipped_conversion_images


def top_folder_name(root: Path, path: Path) -> str:
    try:
        rel = path.relative_to(root)
        return rel.parts[0] if rel.parts else ''
    except Exception:
        return ''


def order_images(images: list[Path], root: Path, descending: bool, start_folder: str | None) -> list[Path]:
    grouped: dict[str, list[Path]] = {}
    for p in images:
        grouped.setdefault(top_folder_name(root, p), []).append(p)
    def folder_key(name: str):
        return (0, int(name)) if str(name).isdigit() else (1, str(name).lower())
    ordered_folders = sorted(grouped.keys(), key=folder_key, reverse=descending)
    if start_folder and start_folder in ordered_folders:
        idx = ordered_folders.index(start_folder)
        ordered_folders = ordered_folders[idx:] + ordered_folders[:idx]
    final = []
    for folder in ordered_folders:
        final.extend(sorted(grouped[folder], key=lambda p: str(p).lower()))
    return final


def process_image(path: Path, destination: Path, detector: EcoTradeDetector, logo_rgba: np.ndarray, use_ocr: bool, dry_run: bool, logo_width: int, gap_x: int, gap_y: int, opacity: float) -> Result:
    started = time.perf_counter()
    try:
        image = cv2.imread(str(path), cv2.IMREAD_UNCHANGED)
        if image is None:
            return Result(path=str(path), status='error', message='Could not read image.', elapsed_seconds=time.perf_counter() - started)
        detection_bgr = cv2.cvtColor(image, cv2.COLOR_GRAY2BGR) if image.ndim == 2 else image[:, :, :3] if image.shape[2] == 4 else image
        visual_found, best_score, second_score, fragment_hits = detector.visual_detected(detection_bgr)
        ocr_found = False
        if not visual_found and use_ocr:
            ocr_found = ocr_contains_ecotrade(detection_bgr)
        if not visual_found and not ocr_found:
            return Result(path=str(path), status='not_found', best_visual_score=best_score, second_visual_score=second_score, visual_fragment_hits=fragment_hits, detection_method='none', elapsed_seconds=time.perf_counter() - started)
        if dry_run:
            return Result(path=str(path), status='would_replace', best_visual_score=best_score, second_visual_score=second_score, visual_fragment_hits=fragment_hits, detection_method='ocr' if ocr_found and not visual_found else 'visual_fragment', elapsed_seconds=time.perf_counter() - started)
        white_bg = whiten_background(image)
        styled = apply_maik_pattern(white_bg, logo_rgba=logo_rgba, logo_width=logo_width, gap_x=gap_x, gap_y=gap_y, opacity=opacity)
        save_image(destination, styled)
        return Result(path=str(path), status='replaced', best_visual_score=best_score, second_visual_score=second_score, visual_fragment_hits=fragment_hits, detection_method='ocr' if ocr_found and not visual_found else 'visual_fragment', elapsed_seconds=time.perf_counter() - started)
    except Exception as exc:
        return Result(path=str(path), status='error', message=str(exc), elapsed_seconds=time.perf_counter() - started)


def write_reports(results: list[Result], summary: dict, report_dir: Path) -> tuple[Path, Path]:
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    csv_path = report_dir / f'ecotrade_replace_{timestamp}.csv'
    json_path = report_dir / f'ecotrade_replace_{timestamp}.json'
    report_dir.mkdir(parents=True, exist_ok=True)
    with csv_path.open('w', newline='', encoding='utf-8-sig') as handle:
        writer = csv.DictWriter(handle, fieldnames=['path', 'status', 'best_visual_score', 'second_visual_score', 'visual_fragment_hits', 'detection_method', 'message', 'elapsed_seconds'])
        writer.writeheader()
        for result in results:
            row = asdict(result)
            for key in ('best_visual_score', 'second_visual_score', 'elapsed_seconds'):
                if row[key] is not None:
                    row[key] = round(row[key], 6)
            writer.writerow(row)
    with json_path.open('w', encoding='utf-8') as handle:
        json.dump({'summary': summary, 'results': [asdict(r) for r in results]}, handle, ensure_ascii=False, indent=2)
    return csv_path, json_path


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description='Parallel Eco Trade detector + replacement with Maik Cat style.')
    parser.add_argument('--root', default=str(DEFAULT_ROOT))
    parser.add_argument('--reference', required=True)
    parser.add_argument('--maik-reference', required=True)
    parser.add_argument('--in-place', action='store_true')
    parser.add_argument('--output')
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--ocr', action='store_true')
    parser.add_argument('--tesseract-cmd')
    parser.add_argument('--strong-threshold', type=float, default=0.82)
    parser.add_argument('--fragment-threshold', type=float, default=0.67)
    parser.add_argument('--second-threshold', type=float, default=0.62)
    parser.add_argument('--min-hits', type=int, default=2)
    parser.add_argument('--max-fragments', type=int, default=42)
    parser.add_argument('--logo-width', type=int, default=180)
    parser.add_argument('--gap-x', type=int, default=290)
    parser.add_argument('--gap-y', type=int, default=190)
    parser.add_argument('--opacity', type=float, default=0.34)
    parser.add_argument('--workers', type=int, default=max(1, min(8, (os.cpu_count() or 4))))
    parser.add_argument('--descending', action='store_true')
    parser.add_argument('--start-folder')
    parser.add_argument('--report-dir')
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(args.root)
    eco_reference_path = Path(args.reference)
    maik_reference_path = Path(args.maik_reference)
    if args.tesseract_cmd and pytesseract is not None:
        pytesseract.pytesseract.tesseract_cmd = args.tesseract_cmd
    if not root.exists():
        print(f'ERROR: Root does not exist: {root}', file=sys.stderr)
        return 2
    if not eco_reference_path.exists():
        print(f'ERROR: Eco Trade reference does not exist: {eco_reference_path}', file=sys.stderr)
        return 2
    if not maik_reference_path.exists():
        print(f'ERROR: Maik Cat reference does not exist: {maik_reference_path}', file=sys.stderr)
        return 2
    if not args.dry_run and not args.in_place and not args.output:
        print('ERROR: Choose --in-place, --output PATH, or use --dry-run.', file=sys.stderr)
        return 2
    if args.ocr:
        if pytesseract is None:
            print('ERROR: pytesseract is not installed.', file=sys.stderr)
            return 2
        try:
            print('Tesseract OCR:', pytesseract.get_tesseract_version())
        except Exception as exc:
            print(f'ERROR using Tesseract: {exc}', file=sys.stderr)
            return 2
    eco_reference_bgr = cv2.imread(str(eco_reference_path), cv2.IMREAD_COLOR)
    maik_reference_bgr = cv2.imread(str(maik_reference_path), cv2.IMREAD_COLOR)
    if eco_reference_bgr is None:
        print(f'ERROR: Could not read Eco Trade reference: {eco_reference_path}', file=sys.stderr)
        return 2
    if maik_reference_bgr is None:
        print(f'ERROR: Could not read Maik Cat reference: {maik_reference_path}', file=sys.stderr)
        return 2
    detector = EcoTradeDetector(eco_reference_bgr, args.strong_threshold, args.fragment_threshold, args.second_threshold, args.min_hits, args.max_fragments)
    logo_rgba = extract_logo_rgba(maik_reference_bgr)
    print('\nEco Trade replacement with Maik Cat style v3 (parallel)')
    print('=' * 76)
    print(f'Root:           {root}')
    print(f'Eco reference:  {eco_reference_path}')
    print(f'Maik reference: {maik_reference_path}')
    print("Skip:           every 'conversions' folder")
    print(f"OCR:            {'enabled' if args.ocr else 'disabled'}")
    print(f"Mode:           {'DRY RUN' if args.dry_run else 'REPLACE ORIGINALS IN PLACE' if args.in_place else 'OUTPUT COPY'}")
    print(f'Workers:        {args.workers}')
    print(f"Descending:     {'yes' if args.descending else 'no'}")
    print(f"Start folder:   {args.start_folder or '(none)'}")
    print('=' * 76 + '\n')
    scan_started = time.perf_counter()
    images, skipped_conversion_images = discover_images(root)
    images = order_images(images, root, descending=args.descending, start_folder=args.start_folder)
    scan_seconds = time.perf_counter() - scan_started
    print(f'Found {len(images):,} product image(s).')
    print(f'Skipped {skipped_conversion_images:,} image(s) inside conversions.')
    print(f'Folder scan + ordering: {scan_seconds:.2f}s\n')
    if not images:
        print('Nothing to process.')
        return 0
    destination_root = root if args.in_place or args.dry_run else Path(args.output)
    results = []
    counters = {'replaced': 0, 'not_found': 0, 'would_replace': 0, 'error': 0}
    started = time.perf_counter()
    with ThreadPoolExecutor(max_workers=max(1, args.workers)) as executor:
        future_to_source = {}
        for source in images:
            relative = source.relative_to(root)
            destination = source if args.in_place or args.dry_run else destination_root / relative
            future = executor.submit(process_image, source, destination, detector, logo_rgba, args.ocr, args.dry_run, args.logo_width, args.gap_x, args.gap_y, args.opacity)
            future_to_source[future] = source
        progress = tqdm(total=len(images), unit='img', dynamic_ncols=True, desc='Checking')
        for future in as_completed(future_to_source):
            result = future.result()
            results.append(result)
            counters[result.status] = counters.get(result.status, 0) + 1
            progress.update(1)
            progress.set_postfix(replaced=counters['replaced'], clean=counters['not_found'], errors=counters['error'], refresh=True)
            if result.status == 'error':
                source = future_to_source[future]
                tqdm.write(f"ERROR: {source.relative_to(root)} -> {result.message}")
        progress.close()
    processing_seconds = time.perf_counter() - started
    results.sort(key=lambda r: r.path.lower())
    report_dir = Path(args.report_dir) if args.report_dir else root / 'watermark-reports'
    summary = {
        'root': str(root), 'eco_reference': str(eco_reference_path), 'maik_reference': str(maik_reference_path),
        'mode': 'dry_run' if args.dry_run else 'in_place' if args.in_place else 'output_copy',
        'ocr_enabled': bool(args.ocr), 'workers': args.workers, 'descending': bool(args.descending), 'start_folder': args.start_folder,
        'images_checked': len(images), 'replaced': counters['replaced'], 'not_found': counters['not_found'], 'would_replace': counters['would_replace'], 'errors': counters['error'],
        'skipped_conversion_images': skipped_conversion_images, 'scan_seconds': round(scan_seconds, 3), 'processing_seconds': round(processing_seconds, 3),
        'average_seconds_per_image': round(processing_seconds / max(1, len(images)), 4), 'finished_at': datetime.now().isoformat(timespec='seconds'),
    }
    csv_path, json_path = write_reports(results, summary, report_dir)
    print('\n' + '=' * 76)
    print('ECO TRADE REPLACEMENT SUMMARY')
    print('=' * 76)
    print(f'Images checked:               {len(images):,}')
    label = 'Would replace' if args.dry_run else 'Replaced in place'
    value = counters['would_replace'] if args.dry_run else counters['replaced']
    print(f'{label + ":":<30} {value:,}')
    print(f"No Eco Trade detected:        {counters['not_found']:,}")
    print(f"Skipped (conversions):        {skipped_conversion_images:,}")
    print(f"Errors:                       {counters['error']:,}")
    print(f'Processing time:              {processing_seconds:.2f}s')
    print(f'Average/image:                {processing_seconds / max(1, len(images)):.3f}s')
    print(f'Workers used:                 {args.workers}')
    print()
    print(f'CSV report:   {csv_path}')
    print(f'JSON report:  {json_path}')
    print('=' * 76)
    return 1 if counters['error'] > 0 else 0


if __name__ == '__main__':
    raise SystemExit(main())
