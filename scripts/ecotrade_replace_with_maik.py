#!/usr/bin/env python3
"""
Eco Trade detector + in-place replacer with Maik Cat watermark.

IMPORTANT:
- This script detects images that contain full or PARTIAL Eco Trade watermark remnants.
- If a match is found and --in-place is used, it OVERWRITES the original image
  with the SAME filename and applies the Maik Cat repeating watermark pattern.
- It does NOT truly remove Eco Trade remnants with AI-quality restoration.
  It is a local automated replacement workflow.

Install:
    py -m pip install opencv-python numpy tqdm pytesseract

Example dry-run:
    py ecotrade_replace_with_maik.py --reference "C:\path\ecotrade-reference.png" --maik-reference "C:\path\maik-cat-reference.png" --dry-run --ocr

Example replace originals:
    py ecotrade_replace_with_maik.py --reference "C:\path\ecotrade-reference.png" --maik-reference "C:\path\maik-cat-reference.png" --in-place --ocr
"""

from __future__ import annotations

import argparse
import csv
import json
import math
import os
import re
import shutil
import sys
import time
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
    return cv2.warpAffine(
        image, matrix, (new_w, new_h),
        flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_CONSTANT, borderValue=255
    )


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
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))
    return mask


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

                score = ink_ratio * 0.55 + edge_density * 0.45
                candidates.append((score, edge_crop))

    candidates.sort(key=lambda item: item[0], reverse=True)
    selected = []
    signatures = set()

    for _, fragment in candidates:
        fh, fw = fragment.shape[:2]
        signature = (round(fw / max(1, fh), 1), int(fw / 40), int(fh / 40))

        if signature in signatures:
            duplicate_count = sum(
                1 for existing in selected
                if round(existing.shape[1] / max(1, existing.shape[0]), 1) == signature[0]
                and int(existing.shape[1] / 40) == signature[1]
                and int(existing.shape[0] / 40) == signature[2]
            )
            if duplicate_count >= 3:
                continue

        selected.append(fragment)
        signatures.add(signature)

        if len(selected) >= max_fragments:
            break

    if len(selected) < 4:
        raise RuntimeError("Could not extract enough Eco Trade fragments from the reference image.")

    return selected


class EcoTradeDetector:
    def __init__(
        self,
        reference_bgr: np.ndarray,
        strong_threshold: float = 0.82,
        fragment_threshold: float = 0.67,
        second_threshold: float = 0.62,
        min_hits: int = 2,
        max_fragments: int = 42,
    ):
        self.reference_bgr = reference_bgr
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
        fragment_best_scores = []

        for fragment in self.fragments:
            best_for_fragment = -1.0

            for multiplier in scale_multipliers:
                scale = expected_scale * multiplier
                tw = max(12, int(round(fragment.shape[1] * scale)))
                th = max(12, int(round(fragment.shape[0] * scale)))

                if tw >= w or th >= h or tw < 10 or th < 10:
                    continue

                resized = cv2.resize(
                    fragment,
                    (tw, th),
                    interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC,
                )

                if np.count_nonzero(resized > 15) < 12:
                    continue

                score = float(np.max(cv2.matchTemplate(target_edge, resized, cv2.TM_CCOEFF_NORMED)))
                if score > best_for_fragment:
                    best_for_fragment = score

            if best_for_fragment >= 0:
                fragment_best_scores.append(best_for_fragment)

        if not fragment_best_scores:
            return -1.0, -1.0, 0

        fragment_best_scores.sort(reverse=True)
        best = fragment_best_scores[0]
        second = fragment_best_scores[1] if len(fragment_best_scores) > 1 else -1.0
        hits = sum(1 for score in fragment_best_scores if score >= self.fragment_threshold)
        return best, second, hits

    def visual_detected(self, image_bgr: np.ndarray) -> tuple[bool, float, float, int]:
        best, second, hits = self.visual_scores(image_bgr)

        if best >= self.strong_threshold:
            return True, best, second, hits

        if best >= self.fragment_threshold and second >= self.second_threshold and hits >= self.min_hits:
            return True, best, second, hits

        return False, best, second, hits


def resize_reference_cover(reference_bgr: np.ndarray, target_w: int, target_h: int) -> np.ndarray:
    ref_h, ref_w = reference_bgr.shape[:2]
    scale = max(target_w / ref_w, target_h / ref_h)
    new_w = max(target_w, int(round(ref_w * scale)))
    new_h = max(target_h, int(round(ref_h * scale)))

    resized = cv2.resize(
        reference_bgr,
        (new_w, new_h),
        interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC,
    )

    x0 = (new_w - target_w) // 2
    y0 = (new_h - target_h) // 2
    return resized[y0:y0 + target_h, x0:x0 + target_w]


def add_maik_watermark(image: np.ndarray, maik_reference_bgr: np.ndarray, ink_gray: int, strength: float) -> np.ndarray:
    if image.ndim == 2:
        image_bgr = cv2.cvtColor(image, cv2.COLOR_GRAY2BGR)
        original_alpha = None
    elif image.shape[2] == 4:
        image_bgr = image[:, :, :3]
        original_alpha = image[:, :, 3].copy()
    else:
        image_bgr = image
        original_alpha = None

    h, w = image_bgr.shape[:2]
    pattern = resize_reference_cover(maik_reference_bgr, w, h)
    pattern_gray = cv2.cvtColor(pattern, cv2.COLOR_BGR2GRAY).astype(np.float32)
    denominator = max(1.0, 255.0 - float(ink_gray))
    alpha = (255.0 - pattern_gray) / denominator
    alpha = np.clip(alpha * strength, 0.0, 0.80)
    alpha = alpha[:, :, None]

    base = image_bgr.astype(np.float32)
    ink = np.full_like(base, float(ink_gray), dtype=np.float32)
    output = np.clip(base * (1.0 - alpha) + ink * alpha, 0, 255).astype(np.uint8)

    if original_alpha is not None:
        output = np.dstack([output, original_alpha])

    return output


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
    images = []
    skipped_conversion_images = 0

    for current_dir, dir_names, file_names in os.walk(root):
        current_path = Path(current_dir)
        kept_dirs = []

        for directory_name in dir_names:
            if directory_name.lower() in SKIP_DIRECTORY_NAMES:
                if directory_name.lower() == "conversions":
                    try:
                        skipped_conversion_images += sum(
                            1
                            for p in (current_path / directory_name).rglob("*")
                            if p.is_file() and p.suffix.lower() in IMAGE_EXTENSIONS
                        )
                    except OSError:
                        pass
            else:
                kept_dirs.append(directory_name)

        dir_names[:] = kept_dirs

        for file_name in file_names:
            path = current_path / file_name
            if path.suffix.lower() in IMAGE_EXTENSIONS:
                images.append(path)

    images.sort(key=lambda p: str(p).lower())
    return images, skipped_conversion_images


def copy_unchanged(source: Path, destination: Path) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, destination)


def process_image(
    path: Path,
    destination: Path,
    detector: EcoTradeDetector,
    maik_reference_bgr: np.ndarray,
    use_ocr: bool,
    dry_run: bool,
    ink_gray: int,
    strength: float,
) -> Result:
    started = time.perf_counter()
    try:
        image = cv2.imread(str(path), cv2.IMREAD_UNCHANGED)
        if image is None:
            return Result(path=str(path), status="error", message="Could not read image.", elapsed_seconds=time.perf_counter() - started)

        if image.ndim == 2:
            detection_bgr = cv2.cvtColor(image, cv2.COLOR_GRAY2BGR)
        elif image.shape[2] == 4:
            detection_bgr = image[:, :, :3]
        else:
            detection_bgr = image

        visual_found, best_score, second_score, fragment_hits = detector.visual_detected(detection_bgr)
        ocr_found = False

        if not visual_found and use_ocr:
            ocr_found = ocr_contains_ecotrade(detection_bgr)

        if not visual_found and not ocr_found:
            if not dry_run and path.resolve() != destination.resolve():
                copy_unchanged(path, destination)
            return Result(
                path=str(path),
                status="not_found",
                best_visual_score=best_score,
                second_visual_score=second_score,
                visual_fragment_hits=fragment_hits,
                detection_method="none",
                elapsed_seconds=time.perf_counter() - started,
            )

        if dry_run:
            return Result(
                path=str(path),
                status="would_replace",
                best_visual_score=best_score,
                second_visual_score=second_score,
                visual_fragment_hits=fragment_hits,
                detection_method="ocr" if ocr_found and not visual_found else "visual_fragment",
                elapsed_seconds=time.perf_counter() - started,
            )

        output = add_maik_watermark(
            image=image,
            maik_reference_bgr=maik_reference_bgr,
            ink_gray=ink_gray,
            strength=strength,
        )
        save_image(destination, output)

        return Result(
            path=str(path),
            status="replaced",
            best_visual_score=best_score,
            second_visual_score=second_score,
            visual_fragment_hits=fragment_hits,
            detection_method="ocr" if ocr_found and not visual_found else "visual_fragment",
            elapsed_seconds=time.perf_counter() - started,
        )

    except Exception as exc:
        return Result(path=str(path), status="error", message=str(exc), elapsed_seconds=time.perf_counter() - started)


def write_reports(results: list[Result], summary: dict, report_dir: Path) -> tuple[Path, Path]:
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    csv_path = report_dir / f"ecotrade_replace_{timestamp}.csv"
    json_path = report_dir / f"ecotrade_replace_{timestamp}.json"
    report_dir.mkdir(parents=True, exist_ok=True)

    with csv_path.open("w", newline="", encoding="utf-8-sig") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=[
                "path", "status", "best_visual_score", "second_visual_score",
                "visual_fragment_hits", "detection_method", "message", "elapsed_seconds"
            ],
        )
        writer.writeheader()
        for result in results:
            row = asdict(result)
            for key in ("best_visual_score", "second_visual_score", "elapsed_seconds"):
                if row[key] is not None:
                    row[key] = round(row[key], 6)
            writer.writerow(row)

    with json_path.open("w", encoding="utf-8") as handle:
        json.dump({"summary": summary, "results": [asdict(r) for r in results]}, handle, ensure_ascii=False, indent=2)

    return csv_path, json_path


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Detect Eco Trade watermark remnants and replace the original files in-place with Maik Cat-watermarked versions.")
    parser.add_argument("--root", default=str(DEFAULT_ROOT), help=f'Media root. Default: "{DEFAULT_ROOT}"')
    parser.add_argument("--reference", required=True, help="Eco Trade reference image used for detection.")
    parser.add_argument("--maik-reference", required=True, help="Maik Cat repeating reference image used for replacement.")
    parser.add_argument("--in-place", action="store_true", help="Overwrite the original image with the same filename.")
    parser.add_argument("--output", help="Optional output root if you do not want in-place replacement.")
    parser.add_argument("--dry-run", action="store_true", help="Detect/report only.")
    parser.add_argument("--ocr", action="store_true", help="Enable OCR fallback detection.")
    parser.add_argument("--tesseract-cmd", help="Path to tesseract.exe.")
    parser.add_argument("--strong-threshold", type=float, default=0.82)
    parser.add_argument("--fragment-threshold", type=float, default=0.67)
    parser.add_argument("--second-threshold", type=float, default=0.62)
    parser.add_argument("--min-hits", type=int, default=2)
    parser.add_argument("--max-fragments", type=int, default=42)
    parser.add_argument("--ink-gray", type=int, default=160)
    parser.add_argument("--strength", type=float, default=1.0)
    parser.add_argument("--report-dir", help="Report folder. Default: <root>\\watermark-reports")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(args.root)
    reference_path = Path(args.reference)
    maik_reference_path = Path(args.maik_reference)

    if args.tesseract_cmd and pytesseract is not None:
        pytesseract.pytesseract.tesseract_cmd = args.tesseract_cmd

    if not root.exists():
        print(f"ERROR: Root does not exist: {root}", file=sys.stderr)
        return 2

    if not reference_path.exists():
        print(f"ERROR: Eco Trade reference does not exist: {reference_path}", file=sys.stderr)
        return 2

    if not maik_reference_path.exists():
        print(f"ERROR: Maik Cat reference does not exist: {maik_reference_path}", file=sys.stderr)
        return 2

    if not args.dry_run and not args.in_place and not args.output:
        print("ERROR: Choose --in-place, --output PATH, or use --dry-run.", file=sys.stderr)
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

    reference_bgr = cv2.imread(str(reference_path), cv2.IMREAD_COLOR)
    maik_reference_bgr = cv2.imread(str(maik_reference_path), cv2.IMREAD_COLOR)

    if reference_bgr is None:
        print(f"ERROR: Could not read Eco Trade reference: {reference_path}", file=sys.stderr)
        return 2

    if maik_reference_bgr is None:
        print(f"ERROR: Could not read Maik Cat reference: {maik_reference_path}", file=sys.stderr)
        return 2

    detector = EcoTradeDetector(
        reference_bgr=reference_bgr,
        strong_threshold=args.strong_threshold,
        fragment_threshold=args.fragment_threshold,
        second_threshold=args.second_threshold,
        min_hits=args.min_hits,
        max_fragments=args.max_fragments,
    )

    print()
    print("Eco Trade detection + replacement with Maik Cat")
    print("=" * 66)
    print(f"Root:           {root}")
    print(f"Eco reference:  {reference_path}")
    print(f"Maik reference: {maik_reference_path}")
    print("Skip:           every 'conversions' folder")
    print(f"OCR:            {'enabled' if args.ocr else 'disabled'}")
    print(f"Mode:           {'DRY RUN' if args.dry_run else 'REPLACE ORIGINALS IN PLACE' if args.in_place else 'OUTPUT COPY'}")
    print("=" * 66)
    print()

    scan_started = time.perf_counter()
    images, skipped_conversion_images = discover_images(root)
    scan_seconds = time.perf_counter() - scan_started

    print(f"Found {len(images):,} product image(s).")
    print(f"Skipped {skipped_conversion_images:,} image(s) inside conversions.")
    print(f"Folder scan: {scan_seconds:.2f}s")
    print()

    if not images:
        print("Nothing to process.")
        return 0

    destination_root = root if args.in_place or args.dry_run else Path(args.output)

    results: list[Result] = []
    counters = {"replaced": 0, "not_found": 0, "would_replace": 0, "error": 0}
    started = time.perf_counter()

    progress = tqdm(images, total=len(images), unit="img", dynamic_ncols=True, desc="Checking")
    for source in progress:
        relative = source.relative_to(root)
        destination = source if args.in_place or args.dry_run else destination_root / relative

        result = process_image(
            path=source,
            destination=destination,
            detector=detector,
            maik_reference_bgr=maik_reference_bgr,
            use_ocr=args.ocr,
            dry_run=args.dry_run,
            ink_gray=args.ink_gray,
            strength=args.strength,
        )

        results.append(result)
        counters[result.status] = counters.get(result.status, 0) + 1
        progress.set_postfix(replaced=counters["replaced"], clean=counters["not_found"], errors=counters["error"], refresh=True)

        if result.status == "error":
            tqdm.write(f"ERROR: {relative} -> {result.message}")

    progress.close()
    processing_seconds = time.perf_counter() - started

    report_dir = Path(args.report_dir) if args.report_dir else root / "watermark-reports"
    summary = {
        "root": str(root),
        "eco_reference": str(reference_path),
        "maik_reference": str(maik_reference_path),
        "mode": "dry_run" if args.dry_run else "in_place" if args.in_place else "output_copy",
        "ocr_enabled": bool(args.ocr),
        "images_checked": len(images),
        "replaced": counters["replaced"],
        "not_found": counters["not_found"],
        "would_replace": counters["would_replace"],
        "errors": counters["error"],
        "skipped_conversion_images": skipped_conversion_images,
        "scan_seconds": round(scan_seconds, 3),
        "processing_seconds": round(processing_seconds, 3),
        "average_seconds_per_image": round(processing_seconds / max(1, len(images)), 4),
        "finished_at": datetime.now().isoformat(timespec="seconds"),
    }

    csv_path, json_path = write_reports(results, summary, report_dir)

    print()
    print("=" * 66)
    print("ECO TRADE REPLACEMENT SUMMARY")
    print("=" * 66)
    print(f"Images checked:               {len(images):,}")
    if args.dry_run:
        print(f"Would replace:                {counters['would_replace']:,}")
    else:
        print(f"Replaced in place:            {counters['replaced']:,}")
    print(f"No Eco Trade detected:        {counters['not_found']:,}")
    print(f"Skipped (conversions):        {skipped_conversion_images:,}")
    print(f"Errors:                       {counters['error']:,}")
    print(f"Processing time:              {processing_seconds:.2f}s")
    print(f"Average/image:                {processing_seconds / max(1, len(images)):.3f}s")
    print()
    print(f"CSV report:   {csv_path}")
    print(f"JSON report:  {json_path}")
    print("=" * 66)

    return 1 if counters["error"] > 0 else 0


if __name__ == "__main__":
    raise SystemExit(main())
