#!/usr/bin/env python3
"""
Maik Cat watermark replacer (in-place capable).

What it does:
- Recursively scans images under the media root.
- Skips every folder named "conversions".
- Detects whether a Maik Cat watermark already exists.
- If missing, applies the Maik Cat repeating watermark pattern.
- With --in-place, it OVERWRITES the original image using the SAME filename.

Install:
    py -m pip install opencv-python numpy tqdm pytesseract

Example dry-run:
    py maik_cat_batch_watermark.py --reference "C:\path\maik-cat-reference.png" --dry-run

Example replace originals:
    py maik_cat_batch_watermark.py --reference "C:\path\maik-cat-reference.png" --in-place
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
IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".webp", ".bmp", ".tif", ".tiff"}
SKIP_DIRS = {"conversions", "watermark-reports", "ecotrade-matches"}


@dataclass
class Result:
    path: str
    status: str
    template_score: float | None = None
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
        image,
        matrix,
        (new_w, new_h),
        flags=cv2.INTER_CUBIC,
        borderMode=cv2.BORDER_CONSTANT,
        borderValue=255,
    )


def ocr_has_maik_cat(image_bgr: np.ndarray) -> bool:
    if pytesseract is None:
        raise RuntimeError("pytesseract is not installed.")

    gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
    gray = cv2.resize(gray, None, fx=2.2, fy=2.2, interpolation=cv2.INTER_CUBIC)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    enhanced = clahe.apply(gray)
    _, thresholded = cv2.threshold(enhanced, 245, 255, cv2.THRESH_BINARY)

    for base in (enhanced, thresholded):
        for angle in (0, -30, -25, -20, -15, 15, 20, 25, 30):
            candidate = base if angle == 0 else rotate_bound(base, angle)
            text = pytesseract.image_to_string(candidate, config="--oem 3 --psm 11")
            compact = normalize_ocr_text(text)
            if "maikcat" in compact or ("maik" in compact and "cat" in compact):
                return True

    return False


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
    x0 = max(0, x - pad)
    y0 = max(0, y - pad)
    x1 = min(ref_w, x + w + pad)
    y1 = min(ref_h, y + h + pad)
    return gray[y0:y1, x0:x1]


class WatermarkDetector:
    def __init__(self, reference_bgr: np.ndarray, template_threshold: float):
        self.reference_bgr = reference_bgr
        self.reference_h, self.reference_w = reference_bgr.shape[:2]
        logo_gray = extract_one_logo_template(reference_bgr)
        self.logo_dark = (255 - logo_gray).astype(np.float32)
        self.template_threshold = template_threshold

    def template_score(self, image_bgr: np.ndarray) -> float:
        target_gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
        target_dark = (255 - target_gray).astype(np.float32)
        h, w = image_bgr.shape[:2]
        expected_scale = math.sqrt((w / self.reference_w) * (h / self.reference_h))

        best = -1.0
        for multiplier in (0.70, 0.80, 0.90, 1.00, 1.10, 1.20, 1.30):
            scale = expected_scale * multiplier
            tw = max(10, int(round(self.logo_dark.shape[1] * scale)))
            th = max(10, int(round(self.logo_dark.shape[0] * scale)))

            if tw >= w or th >= h:
                continue

            logo = cv2.resize(
                self.logo_dark,
                (tw, th),
                interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC,
            )

            score = float(np.max(cv2.matchTemplate(target_dark, logo, cv2.TM_CCOEFF_NORMED)))
            best = max(best, score)

        return best


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


def add_watermark(image: np.ndarray, reference_bgr: np.ndarray, ink_gray: int, strength: float) -> np.ndarray:
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
    pattern = resize_reference_cover(reference_bgr, w, h)
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
        raise RuntimeError(f"OpenCV could not save: {path}")


def discover_images(root: Path) -> tuple[list[Path], int]:
    images = []
    skipped_conversion_images = 0

    for current_dir, dir_names, file_names in os.walk(root):
        current_path = Path(current_dir)
        kept_dirs = []

        for d in dir_names:
            if d.lower() in SKIP_DIRS:
                if d.lower() == "conversions":
                    try:
                        skipped_conversion_images += sum(
                            1 for p in (current_path / d).rglob("*")
                            if p.is_file() and p.suffix.lower() in IMAGE_EXTS
                        )
                    except OSError:
                        pass
            else:
                kept_dirs.append(d)

        dir_names[:] = kept_dirs

        for file_name in file_names:
            path = current_path / file_name
            if path.suffix.lower() in IMAGE_EXTS:
                images.append(path)

    images.sort(key=lambda p: str(p).lower())
    return images, skipped_conversion_images


def copy_unchanged(source: Path, destination: Path) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, destination)


def process_image(
    path: Path,
    destination: Path,
    detector: WatermarkDetector,
    reference_bgr: np.ndarray,
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

        score = detector.template_score(detection_bgr)

        if score >= detector.template_threshold:
            if not dry_run and path.resolve() != destination.resolve():
                copy_unchanged(path, destination)
            return Result(path=str(path), status="already_watermarked", template_score=score, detection_method="template", elapsed_seconds=time.perf_counter() - started)

        if use_ocr:
            if ocr_has_maik_cat(detection_bgr):
                if not dry_run and path.resolve() != destination.resolve():
                    copy_unchanged(path, destination)
                return Result(path=str(path), status="already_watermarked", template_score=score, detection_method="ocr", elapsed_seconds=time.perf_counter() - started)

        if dry_run:
            return Result(path=str(path), status="would_replace", template_score=score, detection_method="not_detected", elapsed_seconds=time.perf_counter() - started)

        output = add_watermark(image=image, reference_bgr=reference_bgr, ink_gray=ink_gray, strength=strength)
        save_image(destination, output)

        return Result(path=str(path), status="replaced", template_score=score, detection_method="not_detected", elapsed_seconds=time.perf_counter() - started)

    except Exception as exc:
        return Result(path=str(path), status="error", message=str(exc), elapsed_seconds=time.perf_counter() - started)


def write_reports(results: list[Result], summary: dict, report_dir: Path) -> tuple[Path, Path]:
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    csv_path = report_dir / f"maik_cat_replace_{timestamp}.csv"
    json_path = report_dir / f"maik_cat_replace_{timestamp}.json"
    report_dir.mkdir(parents=True, exist_ok=True)

    with csv_path.open("w", newline="", encoding="utf-8-sig") as handle:
        import csv
        writer = csv.DictWriter(handle, fieldnames=["path", "status", "template_score", "detection_method", "message", "elapsed_seconds"])
        writer.writeheader()
        for result in results:
            row = asdict(result)
            if row["template_score"] is not None:
                row["template_score"] = round(row["template_score"], 6)
            row["elapsed_seconds"] = round(row["elapsed_seconds"], 4)
            writer.writerow(row)

    with json_path.open("w", encoding="utf-8") as handle:
        json.dump({"summary": summary, "results": [asdict(r) for r in results]}, handle, ensure_ascii=False, indent=2)

    return csv_path, json_path


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Replace original images in-place with a Maik Cat-watermarked version when the watermark is missing.")
    parser.add_argument("--root", default=str(DEFAULT_ROOT), help=f'Media root. Default: "{DEFAULT_ROOT}"')
    parser.add_argument("--reference", required=True, help="Path to the repeating Maik Cat reference image.")
    parser.add_argument("--in-place", action="store_true", help="Overwrite the original image with the same filename.")
    parser.add_argument("--output", help="Optional output root if you do not want in-place replacement.")
    parser.add_argument("--dry-run", action="store_true", help="Only detect/report. Do not replace anything.")
    parser.add_argument("--ocr", action="store_true", help="Enable OCR fallback.")
    parser.add_argument("--tesseract-cmd", help="Path to tesseract.exe.")
    parser.add_argument("--template-threshold", type=float, default=0.72)
    parser.add_argument("--ink-gray", type=int, default=160)
    parser.add_argument("--strength", type=float, default=1.0)
    parser.add_argument("--report-dir", help="Report folder. Default: <root>\\watermark-reports")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(args.root)
    reference_path = Path(args.reference)

    if args.tesseract_cmd and pytesseract is not None:
        pytesseract.pytesseract.tesseract_cmd = args.tesseract_cmd

    if not root.exists():
        print(f"ERROR: Root path not found: {root}", file=sys.stderr)
        return 2

    if not reference_path.exists():
        print(f"ERROR: Reference image not found: {reference_path}", file=sys.stderr)
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
    if reference_bgr is None:
        print(f"ERROR: Could not read reference image: {reference_path}", file=sys.stderr)
        return 2

    detector = WatermarkDetector(reference_bgr=reference_bgr, template_threshold=args.template_threshold)

    print()
    print("Maik Cat watermark replacement")
    print("=" * 66)
    print(f"Root:      {root}")
    print(f"Reference: {reference_path}")
    print("Skip:      every 'conversions' folder")
    print(f"OCR:       {'enabled' if args.ocr else 'disabled'}")
    print(f"Mode:      {'DRY RUN' if args.dry_run else 'REPLACE ORIGINALS IN PLACE' if args.in_place else 'OUTPUT COPY'}")
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
    counters = {"replaced": 0, "already_watermarked": 0, "would_replace": 0, "error": 0}
    started = time.perf_counter()

    progress = tqdm(images, total=len(images), unit="img", dynamic_ncols=True, desc="Checking")
    for source in progress:
        relative = source.relative_to(root)
        destination = source if args.in_place or args.dry_run else destination_root / relative

        result = process_image(
            path=source,
            destination=destination,
            detector=detector,
            reference_bgr=reference_bgr,
            use_ocr=args.ocr,
            dry_run=args.dry_run,
            ink_gray=args.ink_gray,
            strength=args.strength,
        )

        results.append(result)
        counters[result.status] = counters.get(result.status, 0) + 1
        progress.set_postfix(replaced=counters["replaced"], exists=counters["already_watermarked"], errors=counters["error"], refresh=True)

        if result.status == "error":
            tqdm.write(f"ERROR: {relative} -> {result.message}")

    progress.close()
    processing_seconds = time.perf_counter() - started

    report_dir = Path(args.report_dir) if args.report_dir else root / "watermark-reports"
    summary = {
        "root": str(root),
        "reference": str(reference_path),
        "mode": "dry_run" if args.dry_run else "in_place" if args.in_place else "output_copy",
        "ocr_enabled": bool(args.ocr),
        "images_checked": len(images),
        "replaced": counters["replaced"],
        "already_watermarked": counters["already_watermarked"],
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
    print("MAIK CAT SUMMARY")
    print("=" * 66)
    print(f"Images checked:               {len(images):,}")
    print(f"Already watermarked:          {counters['already_watermarked']:,}")
    if args.dry_run:
        print(f"Would replace:                {counters['would_replace']:,}")
    else:
        print(f"Replaced in place:            {counters['replaced']:,}")
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
