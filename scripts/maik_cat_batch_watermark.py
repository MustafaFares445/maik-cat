#!/usr/bin/env python3
"""
Batch Maik Cat watermark checker / adder.

Default media root:
    C:\laragon\www\maik-cars\storage\app\public

Behavior:
- Recursively scans all supported images under the media root.
- Completely skips every directory named "conversions" (case-insensitive).
- Detects an existing Maik Cat watermark using image/template matching.
- Optionally uses Tesseract OCR as a fallback.
- Adds the repeating Maik Cat watermark only when it is not detected.
- Shows real-time progress.
- Prints a final summary and writes CSV + JSON reports.
- Does not overwrite anything unless --in-place is supplied.

Python packages:
    pip install opencv-python numpy pytesseract tqdm

Example:
    python maik_cat_batch_watermark.py ^
      --reference "C:\laragon\www\maik-cars\maik-cat-reference.png" ^
      --in-place ^
      --ocr ^
      --tesseract-cmd "C:\Program Files\Tesseract-OCR\tesseract.exe"
"""

from __future__ import annotations

import argparse
import csv
import json
import math
import re
import shutil
import sys
import time
from dataclasses import dataclass, asdict
from datetime import datetime
from pathlib import Path
from typing import Iterable

import cv2
import numpy as np
import pytesseract
from tqdm import tqdm


DEFAULT_ROOT = Path(r"C:\laragon\www\maik-cars\storage\app\public")

IMAGE_EXTENSIONS = {
    ".jpg",
    ".jpeg",
    ".png",
    ".webp",
    ".bmp",
    ".tif",
    ".tiff",
}

SKIP_DIRECTORY_NAMES = {
    "conversions",
}


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
    """
    OCR fallback.

    Maik Cat watermarks are light and diagonal, so OCR is attempted on
    contrast-enhanced and thresholded versions with several rotations.
    """
    gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)

    # Avoid making very large images unnecessarily huge.
    longest = max(gray.shape[:2])
    scale = 2.5 if longest < 1200 else 1.5
    gray = cv2.resize(
        gray,
        None,
        fx=scale,
        fy=scale,
        interpolation=cv2.INTER_CUBIC,
    )

    clahe = cv2.createCLAHE(clipLimit=2.2, tileGridSize=(8, 8))
    enhanced = clahe.apply(gray)

    _, thresholded = cv2.threshold(
        enhanced,
        245,
        255,
        cv2.THRESH_BINARY,
    )

    versions = (enhanced, thresholded)

    # The supplied watermark is diagonal. These angles cover small variations.
    angles = (0, -30, -25, -20, -15, 15, 20, 25, 30)

    for base in versions:
        for angle in angles:
            candidate = base if angle == 0 else rotate_bound(base, angle)

            text = pytesseract.image_to_string(
                candidate,
                config="--oem 3 --psm 11",
            )

            compact = normalize_ocr_text(text)

            if "maikcat" in compact:
                return True

            if "maik" in compact and "cat" in compact:
                return True

    return False


def extract_one_logo_template(reference_bgr: np.ndarray) -> np.ndarray:
    """
    Extract one complete Maik Cat logo from the full repeating reference image.

    This is done once when the script starts, rather than once per product image.
    """
    gray = cv2.cvtColor(reference_bgr, cv2.COLOR_BGR2GRAY)

    # Light-gray watermark pixels are darker than the near-white background.
    mask = (gray < 248).astype(np.uint8) * 255

    # Join logo text and circular arcs into a single connected component.
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (21, 15))
    merged = cv2.dilate(mask, kernel, iterations=1)

    count, _, stats, _ = cv2.connectedComponentsWithStats(merged)

    ref_h, ref_w = gray.shape
    candidates = []

    for i in range(1, count):
        x, y, w, h, area = stats[i]

        if (
            0.025 * ref_w <= w <= 0.30 * ref_w
            and 0.025 * ref_h <= h <= 0.30 * ref_h
        ):
            candidates.append((area, x, y, w, h))

    if not candidates:
        raise RuntimeError(
            "Could not isolate a Maik Cat logo from the supplied reference image."
        )

    _, x, y, w, h = max(candidates, key=lambda item: item[0])

    pad = max(4, int(min(w, h) * 0.06))
    x0 = max(0, x - pad)
    y0 = max(0, y - pad)
    x1 = min(ref_w, x + w + pad)
    y1 = min(ref_h, y + h + pad)

    return gray[y0:y1, x0:x1]


class WatermarkDetector:
    def __init__(
        self,
        reference_bgr: np.ndarray,
        template_threshold: float,
    ):
        self.reference_bgr = reference_bgr
        self.reference_h, self.reference_w = reference_bgr.shape[:2]
        self.template_threshold = template_threshold

        logo_gray = extract_one_logo_template(reference_bgr)
        self.logo_dark = (255 - logo_gray).astype(np.float32)

    def template_score(self, image_bgr: np.ndarray) -> float:
        target_gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
        target_dark = (255 - target_gray).astype(np.float32)

        h, w = image_bgr.shape[:2]

        expected_scale = math.sqrt(
            (w / self.reference_w) * (h / self.reference_h)
        )

        best = -1.0

        for multiplier in (0.70, 0.80, 0.90, 1.0, 1.10, 1.20, 1.30):
            scale = expected_scale * multiplier

            tw = max(
                10,
                int(round(self.logo_dark.shape[1] * scale)),
            )
            th = max(
                10,
                int(round(self.logo_dark.shape[0] * scale)),
            )

            if tw >= w or th >= h:
                continue

            logo = cv2.resize(
                self.logo_dark,
                (tw, th),
                interpolation=(
                    cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC
                ),
            )

            result = cv2.matchTemplate(
                target_dark,
                logo,
                cv2.TM_CCOEFF_NORMED,
            )

            score = float(np.max(result))
            best = max(best, score)

        return best


def resize_reference_cover(
    reference_bgr: np.ndarray,
    target_w: int,
    target_h: int,
) -> np.ndarray:
    """
    Resize the full repeating watermark pattern while preserving its aspect ratio.
    """
    ref_h, ref_w = reference_bgr.shape[:2]

    scale = max(target_w / ref_w, target_h / ref_h)

    new_w = max(target_w, int(round(ref_w * scale)))
    new_h = max(target_h, int(round(ref_h * scale)))

    resized = cv2.resize(
        reference_bgr,
        (new_w, new_h),
        interpolation=(
            cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC
        ),
    )

    x0 = (new_w - target_w) // 2
    y0 = (new_h - target_h) // 2

    return resized[
        y0 : y0 + target_h,
        x0 : x0 + target_w,
    ]


def add_watermark(
    image: np.ndarray,
    reference_bgr: np.ndarray,
    ink_gray: int,
    strength: float,
) -> np.ndarray:
    """
    Composite the actual reference watermark pattern over the product image.
    """
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

    pattern = resize_reference_cover(
        reference_bgr,
        w,
        h,
    )

    pattern_gray = cv2.cvtColor(
        pattern,
        cv2.COLOR_BGR2GRAY,
    ).astype(np.float32)

    # Convert the reference's white background into transparency.
    denominator = max(
        1.0,
        255.0 - float(ink_gray),
    )

    alpha = (255.0 - pattern_gray) / denominator
    alpha = np.clip(alpha * strength, 0.0, 0.80)
    alpha = alpha[:, :, None]

    base = image_bgr.astype(np.float32)
    ink = np.full_like(
        base,
        float(ink_gray),
        dtype=np.float32,
    )

    output = (
        base * (1.0 - alpha)
        + ink * alpha
    )

    output = np.clip(
        output,
        0,
        255,
    ).astype(np.uint8)

    if original_alpha is not None:
        output = np.dstack(
            [output, original_alpha]
        )

    return output


def save_image(path: Path, image: np.ndarray) -> None:
    path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    extension = path.suffix.lower()

    if extension in {".jpg", ".jpeg"}:
        ok = cv2.imwrite(
            str(path),
            image,
            [cv2.IMWRITE_JPEG_QUALITY, 98],
        )

    elif extension == ".png":
        ok = cv2.imwrite(
            str(path),
            image,
            [cv2.IMWRITE_PNG_COMPRESSION, 3],
        )

    elif extension == ".webp":
        ok = cv2.imwrite(
            str(path),
            image,
            [cv2.IMWRITE_WEBP_QUALITY, 100],
        )

    else:
        ok = cv2.imwrite(
            str(path),
            image,
        )

    if not ok:
        raise RuntimeError(
            f"OpenCV could not save: {path}"
        )


def should_skip_path(path: Path, root: Path) -> bool:
    """
    Skip anything inside a folder named "conversions".
    """
    try:
        relative = path.relative_to(root)
    except ValueError:
        relative = path

    for part in relative.parts[:-1]:
        if part.lower() in SKIP_DIRECTORY_NAMES:
            return True

    return False


def discover_images(root: Path) -> tuple[list[Path], int]:
    """
    Recursively scan images while pruning "conversions" directories.

    Returns:
        (images_to_process, skipped_conversion_file_count)
    """
    images: list[Path] = []
    skipped_conversion_images = 0

    if not root.exists():
        raise FileNotFoundError(
            f"Media root does not exist: {root}"
        )

    # Path.rglob() cannot prune directories efficiently, so use os.walk.
    import os

    for current_dir, dir_names, file_names in os.walk(root):
        current_path = Path(current_dir)

        # Count supported images that will be skipped in direct conversion dirs,
        # then prune those dirs so nothing under them is traversed.
        remaining_dirs = []

        for directory_name in dir_names:
            if directory_name.lower() in SKIP_DIRECTORY_NAMES:
                conversion_dir = current_path / directory_name

                try:
                    skipped_conversion_images += sum(
                        1
                        for p in conversion_dir.rglob("*")
                        if p.is_file()
                        and p.suffix.lower() in IMAGE_EXTENSIONS
                    )
                except OSError:
                    pass
            else:
                remaining_dirs.append(directory_name)

        dir_names[:] = remaining_dirs

        for file_name in file_names:
            path = current_path / file_name

            if path.suffix.lower() not in IMAGE_EXTENSIONS:
                continue

            if should_skip_path(path, root):
                skipped_conversion_images += 1
                continue

            images.append(path)

    images.sort(
        key=lambda p: str(p).lower()
    )

    return images, skipped_conversion_images


def copy_unchanged(
    source: Path,
    destination: Path,
) -> None:
    destination.parent.mkdir(
        parents=True,
        exist_ok=True,
    )
    shutil.copy2(
        source,
        destination,
    )


def process_image(
    path: Path,
    destination: Path,
    detector: WatermarkDetector,
    reference_bgr: np.ndarray,
    use_ocr: bool,
    force: bool,
    dry_run: bool,
    ink_gray: int,
    strength: float,
) -> Result:
    started = time.perf_counter()

    try:
        image = cv2.imread(
            str(path),
            cv2.IMREAD_UNCHANGED,
        )

        if image is None:
            return Result(
                path=str(path),
                status="error",
                message="Could not read image.",
                elapsed_seconds=time.perf_counter() - started,
            )

        if image.ndim == 2:
            detection_bgr = cv2.cvtColor(
                image,
                cv2.COLOR_GRAY2BGR,
            )

        elif image.shape[2] == 4:
            detection_bgr = image[:, :, :3]

        else:
            detection_bgr = image

        score: float | None = None

        if not force:
            score = detector.template_score(
                detection_bgr
            )

            if score >= detector.template_threshold:
                if (
                    not dry_run
                    and path.resolve() != destination.resolve()
                ):
                    copy_unchanged(
                        path,
                        destination,
                    )

                return Result(
                    path=str(path),
                    status="already_watermarked",
                    template_score=score,
                    detection_method="template",
                    elapsed_seconds=time.perf_counter() - started,
                )

            if use_ocr:
                if ocr_has_maik_cat(
                    detection_bgr
                ):
                    if (
                        not dry_run
                        and path.resolve() != destination.resolve()
                    ):
                        copy_unchanged(
                            path,
                            destination,
                        )

                    return Result(
                        path=str(path),
                        status="already_watermarked",
                        template_score=score,
                        detection_method="ocr",
                        elapsed_seconds=time.perf_counter() - started,
                    )

        if dry_run:
            return Result(
                path=str(path),
                status="would_add_watermark",
                template_score=score,
                detection_method="not_detected",
                elapsed_seconds=time.perf_counter() - started,
            )

        output = add_watermark(
            image=image,
            reference_bgr=reference_bgr,
            ink_gray=ink_gray,
            strength=strength,
        )

        save_image(
            destination,
            output,
        )

        return Result(
            path=str(path),
            status="watermark_added",
            template_score=score,
            detection_method="not_detected",
            elapsed_seconds=time.perf_counter() - started,
        )

    except Exception as exc:
        return Result(
            path=str(path),
            status="error",
            message=str(exc),
            elapsed_seconds=time.perf_counter() - started,
        )


def report_paths(
    report_directory: Path,
) -> tuple[Path, Path]:
    timestamp = datetime.now().strftime(
        "%Y%m%d_%H%M%S"
    )

    return (
        report_directory
        / f"maik_cat_watermark_report_{timestamp}.csv",
        report_directory
        / f"maik_cat_watermark_report_{timestamp}.json",
    )


def write_reports(
    results: list[Result],
    csv_path: Path,
    json_path: Path,
    summary: dict,
) -> None:
    csv_path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    with csv_path.open(
        "w",
        newline="",
        encoding="utf-8-sig",
    ) as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=[
                "path",
                "status",
                "template_score",
                "detection_method",
                "message",
                "elapsed_seconds",
            ],
        )

        writer.writeheader()

        for result in results:
            row = asdict(result)
            if row["template_score"] is not None:
                row["template_score"] = round(
                    row["template_score"],
                    6,
                )
            row["elapsed_seconds"] = round(
                row["elapsed_seconds"],
                4,
            )
            writer.writerow(row)

    with json_path.open(
        "w",
        encoding="utf-8",
    ) as handle:
        json.dump(
            {
                "summary": summary,
                "results": [
                    asdict(result)
                    for result in results
                ],
            },
            handle,
            ensure_ascii=False,
            indent=2,
        )


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Recursively detect/add Maik Cat watermarks "
            "while skipping all conversions folders."
        )
    )

    parser.add_argument(
        "--root",
        default=str(DEFAULT_ROOT),
        help=(
            "Root media path. "
            f'Default: "{DEFAULT_ROOT}"'
        ),
    )

    parser.add_argument(
        "--reference",
        required=True,
        help=(
            "Path to the full repeating Maik Cat "
            "watermark reference image."
        ),
    )

    destination_group = parser.add_mutually_exclusive_group()

    destination_group.add_argument(
        "--in-place",
        action="store_true",
        help=(
            "Update product images in their current locations."
        ),
    )

    destination_group.add_argument(
        "--output",
        help=(
            "Write processed images to another root directory, "
            "preserving the same subfolder structure."
        ),
    )

    parser.add_argument(
        "--dry-run",
        action="store_true",
        help=(
            "Detect only. Do not modify/copy any product image."
        ),
    )

    parser.add_argument(
        "--ocr",
        action="store_true",
        help=(
            "Enable Tesseract OCR fallback when template matching "
            "does not detect the watermark. More accurate but slower."
        ),
    )

    parser.add_argument(
        "--tesseract-cmd",
        help=(
            "Path to tesseract.exe. Example: "
            r'C:\Program Files\Tesseract-OCR\tesseract.exe'
        ),
    )

    parser.add_argument(
        "--template-threshold",
        type=float,
        default=0.72,
        help=(
            "Normalized template-match threshold. Default: 0.72"
        ),
    )

    parser.add_argument(
        "--ink-gray",
        type=int,
        default=160,
        help=(
            "Watermark gray value: 0 black, 255 white. Default: 160"
        ),
    )

    parser.add_argument(
        "--strength",
        type=float,
        default=1.0,
        help=(
            "Watermark opacity multiplier. Default: 1.0"
        ),
    )

    parser.add_argument(
        "--report-dir",
        help=(
            "Directory for CSV/JSON reports. "
            "Default: <root>\\watermark-reports"
        ),
    )

    return parser.parse_args()


def main() -> int:
    args = parse_arguments()

    root = Path(args.root)
    reference_path = Path(args.reference)

    if args.tesseract_cmd:
        pytesseract.pytesseract.tesseract_cmd = (
            args.tesseract_cmd
        )

    if not reference_path.exists():
        print(
            f"ERROR: Reference image not found: {reference_path}",
            file=sys.stderr,
        )
        return 2

    if not root.exists():
        print(
            f"ERROR: Root path not found: {root}",
            file=sys.stderr,
        )
        return 2

    if not args.dry_run and not args.in_place and not args.output:
        print(
            "ERROR: Choose --in-place, --output PATH, or use --dry-run.",
            file=sys.stderr,
        )
        return 2

    if args.ocr:
        try:
            version = pytesseract.get_tesseract_version()
            print(
                f"Tesseract OCR: {version}"
            )
        except Exception as exc:
            print(
                "ERROR: --ocr was supplied but Tesseract could not be used.\n"
                "Install Tesseract OCR and/or pass --tesseract-cmd.\n"
                f"Details: {exc}",
                file=sys.stderr,
            )
            return 2

    reference_bgr = cv2.imread(
        str(reference_path),
        cv2.IMREAD_COLOR,
    )

    if reference_bgr is None:
        print(
            f"ERROR: Could not read reference image: {reference_path}",
            file=sys.stderr,
        )
        return 2

    try:
        detector = WatermarkDetector(
            reference_bgr=reference_bgr,
            template_threshold=args.template_threshold,
        )
    except Exception as exc:
        print(
            f"ERROR preparing watermark detector: {exc}",
            file=sys.stderr,
        )
        return 2

    print()
    print("Scanning media directories...")
    print(f"Root:       {root}")
    print(f"Reference:  {reference_path}")
    print("Skip dirs:  conversions")
    print(
        f"OCR:        {'enabled' if args.ocr else 'disabled'}"
    )
    print(
        f"Mode:       "
        f"{'DRY RUN' if args.dry_run else 'IN PLACE' if args.in_place else 'OUTPUT COPY'}"
    )
    print()

    scan_started = time.perf_counter()

    try:
        images, skipped_conversion_images = discover_images(
            root
        )
    except Exception as exc:
        print(
            f"ERROR while scanning: {exc}",
            file=sys.stderr,
        )
        return 2

    scan_seconds = time.perf_counter() - scan_started

    print(
        f"Found {len(images):,} product image(s) to check."
    )
    print(
        f"Skipped {skipped_conversion_images:,} image(s) inside conversions folders."
    )
    print(
        f"Scan completed in {scan_seconds:.2f}s."
    )
    print()

    if not images:
        print(
            "Nothing to process."
        )
        return 0

    if args.in_place:
        destination_root = root

    elif args.output:
        destination_root = Path(
            args.output
        )

    else:
        # dry-run path; destination is never written.
        destination_root = root

    report_directory = (
        Path(args.report_dir)
        if args.report_dir
        else root / "watermark-reports"
    )

    results: list[Result] = []

    counters = {
        "watermark_added": 0,
        "already_watermarked": 0,
        "would_add_watermark": 0,
        "error": 0,
    }

    started = time.perf_counter()

    progress = tqdm(
        images,
        total=len(images),
        unit="img",
        dynamic_ncols=True,
        desc="Checking",
    )

    for source in progress:
        relative = source.relative_to(
            root
        )

        destination = (
            source
            if args.in_place or args.dry_run
            else destination_root / relative
        )

        result = process_image(
            path=source,
            destination=destination,
            detector=detector,
            reference_bgr=reference_bgr,
            use_ocr=args.ocr,
            force=False,
            dry_run=args.dry_run,
            ink_gray=args.ink_gray,
            strength=args.strength,
        )

        results.append(
            result
        )

        counters[result.status] = (
            counters.get(
                result.status,
                0,
            )
            + 1
        )

        progress.set_postfix(
            added=counters["watermark_added"],
            exists=counters["already_watermarked"],
            errors=counters["error"],
            refresh=True,
        )

        if result.status == "error":
            tqdm.write(
                f"ERROR: {relative} -> {result.message}"
            )

    progress.close()

    total_seconds = time.perf_counter() - started

    summary = {
        "root": str(root),
        "reference": str(reference_path),
        "mode": (
            "dry_run"
            if args.dry_run
            else "in_place"
            if args.in_place
            else "output_copy"
        ),
        "ocr_enabled": bool(args.ocr),
        "template_threshold": args.template_threshold,
        "total_images_discovered": len(images),
        "skipped_conversion_images": skipped_conversion_images,
        "already_watermarked": counters["already_watermarked"],
        "watermark_added": counters["watermark_added"],
        "would_add_watermark": counters["would_add_watermark"],
        "errors": counters["error"],
        "scan_seconds": round(scan_seconds, 3),
        "processing_seconds": round(total_seconds, 3),
        "average_seconds_per_image": round(
            total_seconds / len(images),
            4,
        ),
        "finished_at": datetime.now().isoformat(
            timespec="seconds"
        ),
    }

    csv_path, json_path = report_paths(
        report_directory
    )

    try:
        write_reports(
            results=results,
            csv_path=csv_path,
            json_path=json_path,
            summary=summary,
        )
    except Exception as exc:
        print()
        print(
            f"WARNING: Could not write report files: {exc}",
            file=sys.stderr,
        )

    print()
    print("=" * 64)
    print("MAIK CAT WATERMARK SUMMARY")
    print("=" * 64)
    print(
        f"Images checked:              {len(images):,}"
    )
    print(
        f"Already watermarked:         {counters['already_watermarked']:,}"
    )

    if args.dry_run:
        print(
            f"Would add watermark:         {counters['would_add_watermark']:,}"
        )
    else:
        print(
            f"Watermarks added:            {counters['watermark_added']:,}"
        )

    print(
        f"Images skipped (conversions): {skipped_conversion_images:,}"
    )
    print(
        f"Errors:                      {counters['error']:,}"
    )
    print(
        f"Processing time:             {total_seconds:.2f}s"
    )
    print(
        f"Average/image:               "
        f"{total_seconds / len(images):.3f}s"
    )
    print()
    print(
        f"CSV report:  {csv_path}"
    )
    print(
        f"JSON report: {json_path}"
    )
    print("=" * 64)

    return (
        1
        if counters["error"] > 0
        else 0
    )


if __name__ == "__main__":
    raise SystemExit(main())
