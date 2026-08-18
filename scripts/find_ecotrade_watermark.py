#!/usr/bin/env python3
"""
Eco Trade watermark finder.

Scans:
    C:\laragon\www\maik-cars\storage\app\public

Features:
- Recursively scans product images.
- Completely skips all folders named "conversions".
- Detects full OR PARTIAL Eco Trade watermark remains.
- Uses a bank of visual fragments automatically extracted from the supplied
  Eco Trade reference image.
- Optional Tesseract OCR fallback checks "eco", "trade", "ecotrade" and
  useful partial OCR fragments at several rotations.
- Shows real-time progress with tqdm.
- DOES NOT modify product images.
- Writes:
    * CSV report
    * JSON report
    * TXT list containing only matched image paths
- Optional --copy-matches can collect matches into a separate review folder.

Install Python packages:
    py -m pip install opencv-python numpy tqdm pytesseract

Example:
    py "C:\laragon\www\maik-cars\scripts\find_ecotrade_watermark.py" `
      --root "C:\laragon\www\maik-cars\storage\app\public" `
      --reference "C:\laragon\www\maik-cars\scripts\ecotrade-reference.png" `
      --ocr `
      --tesseract-cmd "C:\Program Files\Tesseract-OCR\tesseract.exe"
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

IMAGE_EXTENSIONS = {
    ".jpg", ".jpeg", ".png", ".webp",
    ".bmp", ".tif", ".tiff",
}

SKIP_DIRECTORY_NAMES = {
    "conversions",
    "watermark-reports",
    "ecotrade-matches",
}


@dataclass
class Result:
    path: str
    status: str
    best_visual_score: float | None = None
    second_visual_score: float | None = None
    visual_fragment_hits: int = 0
    detection_method: str | None = None
    ocr_text: str | None = None
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


def ocr_contains_ecotrade(image_bgr: np.ndarray) -> tuple[bool, str]:
    """
    OCR fallback for full and partial Eco Trade text.

    Because the watermark is diagonal, several rotations are checked.
    We intentionally accept some partial strings because the user's images
    may contain only remnants of the watermark.
    """
    if pytesseract is None:
        raise RuntimeError(
            "pytesseract is not installed. Run: "
            "py -m pip install pytesseract"
        )

    gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)

    longest = max(gray.shape[:2])
    scale = 2.4 if longest < 1200 else 1.5

    gray = cv2.resize(
        gray,
        None,
        fx=scale,
        fy=scale,
        interpolation=cv2.INTER_CUBIC,
    )

    clahe = cv2.createCLAHE(
        clipLimit=2.5,
        tileGridSize=(8, 8),
    )
    enhanced = clahe.apply(gray)

    # Faint watermark text often becomes easier to OCR after a high threshold.
    _, thresholded = cv2.threshold(
        enhanced,
        245,
        255,
        cv2.THRESH_BINARY,
    )

    # Also use adaptive threshold for watermark fragments over the product.
    adaptive = cv2.adaptiveThreshold(
        enhanced,
        255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY,
        41,
        11,
    )

    versions = (
        enhanced,
        thresholded,
        adaptive,
    )

    # Eco Trade reference rises from left to right. After rotation one of these
    # should make the text close to horizontal.
    angles = (
        0, -35, -30, -25, -20, -15,
        15, 20, 25, 30, 35,
    )

    # Long enough to avoid matching random OCR noise too easily.
    partial_markers = (
        "ecotrade",
        "ecotra",
        "cotrade",
        "ecotr",
        "trade",
        "trad",
        "ecot",
        "eco",
    )

    collected = []

    for base in versions:
        for angle in angles:
            candidate = (
                base
                if angle == 0
                else rotate_bound(base, angle)
            )

            text = pytesseract.image_to_string(
                candidate,
                config="--oem 3 --psm 11",
            )

            if text.strip():
                collected.append(text.strip())

            compact = normalize_ocr_text(text)

            if any(marker in compact for marker in partial_markers):
                return True, " | ".join(collected)[-1000:]

            # Handle split OCR such as "eco" ... "trade".
            words = re.findall(
                r"[a-z]+",
                text.lower(),
            )
            joined = "".join(words)

            if (
                ("eco" in words and "trade" in words)
                or "ecotrade" in joined
            ):
                return True, " | ".join(collected)[-1000:]

    return False, " | ".join(collected)[-1000:]


def reference_ink_mask(reference_gray: np.ndarray) -> np.ndarray:
    """
    Reference background is near-white. Watermark is light gray.
    Convert it into a binary ink mask.
    """
    # Use the white border/background itself to estimate threshold robustly.
    bg = float(np.percentile(reference_gray, 92))
    threshold = max(225.0, min(250.0, bg - 5.0))

    mask = (
        reference_gray.astype(np.float32)
        < threshold
    ).astype(np.uint8) * 255

    # Remove isolated compression/noise.
    mask = cv2.morphologyEx(
        mask,
        cv2.MORPH_OPEN,
        np.ones((2, 2), np.uint8),
    )

    return mask


def make_edge_image(gray: np.ndarray) -> np.ndarray:
    """
    Edge representation helps match watermark fragments even when the fragment
    is over metal rather than a white background.
    """
    blur = cv2.GaussianBlur(
        gray,
        (0, 0),
        1.0,
    )

    # Enhance light/dark local differences.
    detail = cv2.absdiff(
        gray,
        blur,
    )

    detail = cv2.normalize(
        detail,
        None,
        0,
        255,
        cv2.NORM_MINMAX,
    ).astype(np.uint8)

    edges = cv2.Canny(
        gray,
        30,
        100,
    )

    # Combine edge and local-contrast information.
    combined = cv2.max(
        edges,
        detail,
    )

    return combined


def extract_fragment_bank(
    reference_bgr: np.ndarray,
    max_fragments: int = 42,
) -> list[np.ndarray]:
    """
    Automatically extract many visual fragments from the Eco Trade reference.

    This is important because a target image may contain only:
      "eco"
      "trade"
      a few letters
      part of the circular Eco Trade logo
      clipped/faded remnants

    Rather than requiring the complete watermark, the detector compares
    independent local fragments.
    """
    gray = cv2.cvtColor(
        reference_bgr,
        cv2.COLOR_BGR2GRAY,
    )

    mask = reference_ink_mask(
        gray
    )

    h, w = gray.shape[:2]

    # Multiple tile sizes catch both letter groups and logo fragments.
    tile_specs = (
        (0.12, 0.10),
        (0.16, 0.12),
        (0.20, 0.14),
    )

    candidates: list[tuple[float, np.ndarray]] = []

    for wf, hf in tile_specs:
        tile_w = max(
            36,
            int(w * wf),
        )
        tile_h = max(
            32,
            int(h * hf),
        )

        step_x = max(
            20,
            tile_w // 3,
        )
        step_y = max(
            18,
            tile_h // 3,
        )

        for y in range(
            0,
            max(1, h - tile_h + 1),
            step_y,
        ):
            for x in range(
                0,
                max(1, w - tile_w + 1),
                step_x,
            ):
                tile_mask = mask[
                    y:y + tile_h,
                    x:x + tile_w,
                ]

                ink_ratio = float(
                    np.count_nonzero(tile_mask)
                ) / float(tile_mask.size)

                # Reject empty areas and tiles almost completely filled.
                if ink_ratio < 0.018 or ink_ratio > 0.40:
                    continue

                ys, xs = np.where(
                    tile_mask > 0
                )

                if len(xs) < 20:
                    continue

                min_x = max(
                    0,
                    int(xs.min()) - 10,
                )
                max_x = min(
                    tile_w,
                    int(xs.max()) + 11,
                )
                min_y = max(
                    0,
                    int(ys.min()) - 10,
                )
                max_y = min(
                    tile_h,
                    int(ys.max()) + 11,
                )

                crop = gray[
                    y + min_y:y + max_y,
                    x + min_x:x + max_x,
                ]

                if (
                    crop.shape[0] < 25
                    or crop.shape[1] < 25
                ):
                    continue

                edge_crop = make_edge_image(
                    crop
                )

                edge_density = float(
                    np.count_nonzero(
                        edge_crop > 18
                    )
                ) / float(edge_crop.size)

                if edge_density < 0.018:
                    continue

                # Favor fragments with enough structured watermark information.
                score = (
                    ink_ratio * 0.55
                    + edge_density * 0.45
                )

                candidates.append(
                    (score, edge_crop)
                )

    candidates.sort(
        key=lambda item: item[0],
        reverse=True,
    )

    selected: list[np.ndarray] = []

    # Keep varied fragment aspect ratios/sizes instead of many near duplicates.
    signatures = set()

    for _, fragment in candidates:
        fh, fw = fragment.shape[:2]

        signature = (
            round(
                fw / max(1, fh),
                1,
            ),
            int(fw / 40),
            int(fh / 40),
        )

        if signature in signatures:
            # Permit a few duplicates because they may represent different letters.
            duplicate_count = sum(
                1
                for existing in selected
                if (
                    round(
                        existing.shape[1] / max(1, existing.shape[0]),
                        1,
                    )
                    == signature[0]
                    and int(existing.shape[1] / 40) == signature[1]
                    and int(existing.shape[0] / 40) == signature[2]
                )
            )

            if duplicate_count >= 3:
                continue

        selected.append(
            fragment
        )
        signatures.add(
            signature
        )

        if len(selected) >= max_fragments:
            break

    if len(selected) < 4:
        raise RuntimeError(
            "Could not extract enough Eco Trade fragments from the reference image."
        )

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
        self.reference_h, self.reference_w = (
            reference_bgr.shape[:2]
        )

        self.strong_threshold = strong_threshold
        self.fragment_threshold = fragment_threshold
        self.second_threshold = second_threshold
        self.min_hits = min_hits

        self.fragments = extract_fragment_bank(
            reference_bgr,
            max_fragments=max_fragments,
        )

    def visual_scores(
        self,
        image_bgr: np.ndarray,
    ) -> tuple[float, float, int]:
        target_gray = cv2.cvtColor(
            image_bgr,
            cv2.COLOR_BGR2GRAY,
        )

        target_edge = make_edge_image(
            target_gray
        )

        h, w = image_bgr.shape[:2]

        # Estimate typical reference->target scale, then allow broad variation.
        expected_scale = math.sqrt(
            (w / self.reference_w)
            * (h / self.reference_h)
        )

        scale_multipliers = (
            0.55,
            0.70,
            0.85,
            1.00,
            1.15,
            1.30,
            1.50,
        )

        fragment_best_scores = []

        for fragment in self.fragments:
            best_for_fragment = -1.0

            for multiplier in scale_multipliers:
                scale = (
                    expected_scale
                    * multiplier
                )

                tw = max(
                    12,
                    int(
                        round(
                            fragment.shape[1]
                            * scale
                        )
                    ),
                )
                th = max(
                    12,
                    int(
                        round(
                            fragment.shape[0]
                            * scale
                        )
                    ),
                )

                if (
                    tw >= w
                    or th >= h
                    or tw < 10
                    or th < 10
                ):
                    continue

                resized = cv2.resize(
                    fragment,
                    (tw, th),
                    interpolation=(
                        cv2.INTER_AREA
                        if scale < 1
                        else cv2.INTER_CUBIC
                    ),
                )

                # Skip nearly empty resized fragment.
                if (
                    np.count_nonzero(
                        resized > 15
                    )
                    < 12
                ):
                    continue

                result = cv2.matchTemplate(
                    target_edge,
                    resized,
                    cv2.TM_CCOEFF_NORMED,
                )

                score = float(
                    np.max(result)
                )

                if score > best_for_fragment:
                    best_for_fragment = score

            if best_for_fragment >= 0:
                fragment_best_scores.append(
                    best_for_fragment
                )

        if not fragment_best_scores:
            return -1.0, -1.0, 0

        fragment_best_scores.sort(
            reverse=True
        )

        best = fragment_best_scores[0]
        second = (
            fragment_best_scores[1]
            if len(fragment_best_scores) > 1
            else -1.0
        )

        hits = sum(
            1
            for score in fragment_best_scores
            if score >= self.fragment_threshold
        )

        return best, second, hits

    def visual_detected(
        self,
        image_bgr: np.ndarray,
    ) -> tuple[bool, float, float, int]:
        best, second, hits = self.visual_scores(
            image_bgr
        )

        # One extremely strong partial fragment can be sufficient.
        if best >= self.strong_threshold:
            return True, best, second, hits

        # Otherwise require multiple supporting watermark fragments.
        if (
            best >= self.fragment_threshold
            and second >= self.second_threshold
            and hits >= self.min_hits
        ):
            return True, best, second, hits

        return False, best, second, hits


def discover_images(
    root: Path,
) -> tuple[list[Path], int]:
    images: list[Path] = []
    skipped_conversion_images = 0

    if not root.exists():
        raise FileNotFoundError(
            f"Root path does not exist: {root}"
        )

    for (
        current_dir,
        dir_names,
        file_names,
    ) in os.walk(root):
        current_path = Path(
            current_dir
        )

        kept_dirs = []

        for directory_name in dir_names:
            if (
                directory_name.lower()
                in SKIP_DIRECTORY_NAMES
            ):
                skipped_dir = (
                    current_path
                    / directory_name
                )

                if (
                    directory_name.lower()
                    == "conversions"
                ):
                    try:
                        skipped_conversion_images += sum(
                            1
                            for p in skipped_dir.rglob("*")
                            if (
                                p.is_file()
                                and p.suffix.lower()
                                in IMAGE_EXTENSIONS
                            )
                        )
                    except OSError:
                        pass
            else:
                kept_dirs.append(
                    directory_name
                )

        # Prune skipped directories.
        dir_names[:] = kept_dirs

        for file_name in file_names:
            path = (
                current_path
                / file_name
            )

            if (
                path.suffix.lower()
                not in IMAGE_EXTENSIONS
            ):
                continue

            images.append(
                path
            )

    images.sort(
        key=lambda p: str(p).lower()
    )

    return (
        images,
        skipped_conversion_images,
    )


def process_image(
    path: Path,
    detector: EcoTradeDetector,
    use_ocr: bool,
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
                elapsed_seconds=(
                    time.perf_counter()
                    - started
                ),
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

        (
            visual_found,
            best_score,
            second_score,
            fragment_hits,
        ) = detector.visual_detected(
            detection_bgr
        )

        if visual_found:
            return Result(
                path=str(path),
                status="eco_trade_found",
                best_visual_score=best_score,
                second_visual_score=second_score,
                visual_fragment_hits=fragment_hits,
                detection_method="visual_fragment",
                elapsed_seconds=(
                    time.perf_counter()
                    - started
                ),
            )

        if use_ocr:
            ocr_found, ocr_text = (
                ocr_contains_ecotrade(
                    detection_bgr
                )
            )

            if ocr_found:
                return Result(
                    path=str(path),
                    status="eco_trade_found",
                    best_visual_score=best_score,
                    second_visual_score=second_score,
                    visual_fragment_hits=fragment_hits,
                    detection_method="ocr",
                    ocr_text=ocr_text,
                    elapsed_seconds=(
                        time.perf_counter()
                        - started
                    ),
                )

            return Result(
                path=str(path),
                status="not_found",
                best_visual_score=best_score,
                second_visual_score=second_score,
                visual_fragment_hits=fragment_hits,
                detection_method="none",
                ocr_text=ocr_text,
                elapsed_seconds=(
                    time.perf_counter()
                    - started
                ),
            )

        return Result(
            path=str(path),
            status="not_found",
            best_visual_score=best_score,
            second_visual_score=second_score,
            visual_fragment_hits=fragment_hits,
            detection_method="none",
            elapsed_seconds=(
                time.perf_counter()
                - started
            ),
        )

    except Exception as exc:
        return Result(
            path=str(path),
            status="error",
            message=str(exc),
            elapsed_seconds=(
                time.perf_counter()
                - started
            ),
        )


def create_report_paths(
    report_dir: Path,
) -> tuple[Path, Path, Path]:
    timestamp = datetime.now().strftime(
        "%Y%m%d_%H%M%S"
    )

    return (
        report_dir
        / f"ecotrade_scan_{timestamp}.csv",
        report_dir
        / f"ecotrade_scan_{timestamp}.json",
        report_dir
        / f"ecotrade_matches_{timestamp}.txt",
    )


def write_reports(
    results: list[Result],
    summary: dict,
    csv_path: Path,
    json_path: Path,
    matches_path: Path,
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
                "best_visual_score",
                "second_visual_score",
                "visual_fragment_hits",
                "detection_method",
                "ocr_text",
                "message",
                "elapsed_seconds",
            ],
        )

        writer.writeheader()

        for result in results:
            row = asdict(result)

            for key in (
                "best_visual_score",
                "second_visual_score",
                "elapsed_seconds",
            ):
                if row[key] is not None:
                    row[key] = round(
                        row[key],
                        6,
                    )

            writer.writerow(
                row
            )

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

    with matches_path.open(
        "w",
        encoding="utf-8",
    ) as handle:
        for result in results:
            if (
                result.status
                == "eco_trade_found"
            ):
                handle.write(
                    result.path + "\n"
                )


def copy_matches(
    root: Path,
    results: list[Result],
    destination_root: Path,
) -> tuple[int, int]:
    copied = 0
    errors = 0

    matches = [
        result
        for result in results
        if result.status == "eco_trade_found"
    ]

    if not matches:
        return 0, 0

    print()
    print(
        f"Copying {len(matches):,} match(es) for review..."
    )

    for result in tqdm(
        matches,
        unit="img",
        dynamic_ncols=True,
        desc="Copying",
    ):
        try:
            source = Path(
                result.path
            )
            relative = source.relative_to(
                root
            )
            destination = (
                destination_root
                / relative
            )

            destination.parent.mkdir(
                parents=True,
                exist_ok=True,
            )

            shutil.copy2(
                source,
                destination,
            )

            copied += 1

        except Exception:
            errors += 1

    return copied, errors


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Find full or partial Eco Trade watermarks "
            "in the Maik Cars media directory."
        )
    )

    parser.add_argument(
        "--root",
        default=str(DEFAULT_ROOT),
        help=(
            "Media root. Default: "
            f'"{DEFAULT_ROOT}"'
        ),
    )

    parser.add_argument(
        "--reference",
        required=True,
        help=(
            "Path to the supplied Eco Trade watermark "
            "reference image."
        ),
    )

    parser.add_argument(
        "--ocr",
        action="store_true",
        help=(
            "Enable OCR fallback. Slower but helpful "
            "for partial Eco Trade letters."
        ),
    )

    parser.add_argument(
        "--tesseract-cmd",
        help=(
            "Optional path to tesseract.exe."
        ),
    )

    parser.add_argument(
        "--strong-threshold",
        type=float,
        default=0.82,
        help=(
            "One-fragment strong visual threshold. Default: 0.82"
        ),
    )

    parser.add_argument(
        "--fragment-threshold",
        type=float,
        default=0.67,
        help=(
            "Visual fragment hit threshold. Default: 0.67"
        ),
    )

    parser.add_argument(
        "--second-threshold",
        type=float,
        default=0.62,
        help=(
            "Second supporting visual fragment threshold. Default: 0.62"
        ),
    )

    parser.add_argument(
        "--min-hits",
        type=int,
        default=2,
        help=(
            "Minimum visual fragment hits when there is "
            "no single strong match. Default: 2"
        ),
    )

    parser.add_argument(
        "--max-fragments",
        type=int,
        default=42,
        help=(
            "Maximum number of reference fragments. Default: 42"
        ),
    )

    parser.add_argument(
        "--report-dir",
        help=(
            "Report directory. Default: "
            "<root>\\watermark-reports"
        ),
    )

    parser.add_argument(
        "--copy-matches",
        help=(
            "Optional destination directory to copy matching "
            "images for manual review. Originals are unchanged."
        ),
    )

    return parser.parse_args()


def main() -> int:
    args = parse_args()

    root = Path(
        args.root
    )
    reference_path = Path(
        args.reference
    )

    if not root.exists():
        print(
            f"ERROR: Root does not exist: {root}",
            file=sys.stderr,
        )
        return 2

    if not reference_path.exists():
        print(
            f"ERROR: Reference does not exist: {reference_path}",
            file=sys.stderr,
        )
        return 2

    if args.ocr:
        if pytesseract is None:
            print(
                "ERROR: pytesseract is not installed.\n"
                "Run: py -m pip install pytesseract",
                file=sys.stderr,
            )
            return 2

        if args.tesseract_cmd:
            pytesseract.pytesseract.tesseract_cmd = (
                args.tesseract_cmd
            )

        try:
            print(
                "Tesseract OCR:",
                pytesseract.get_tesseract_version(),
            )
        except Exception as exc:
            print(
                "ERROR: Tesseract OCR could not be used.\n"
                "Install Tesseract or pass --tesseract-cmd.\n"
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
            f"ERROR: Could not read reference: {reference_path}",
            file=sys.stderr,
        )
        return 2

    try:
        detector = EcoTradeDetector(
            reference_bgr=reference_bgr,
            strong_threshold=args.strong_threshold,
            fragment_threshold=args.fragment_threshold,
            second_threshold=args.second_threshold,
            min_hits=args.min_hits,
            max_fragments=args.max_fragments,
        )
    except Exception as exc:
        print(
            f"ERROR preparing detector: {exc}",
            file=sys.stderr,
        )
        return 2

    print()
    print("Eco Trade watermark scan")
    print("=" * 66)
    print(f"Root:      {root}")
    print(f"Reference: {reference_path}")
    print("Skip:      every 'conversions' folder")
    print(
        f"OCR:       {'enabled' if args.ocr else 'disabled'}"
    )
    print(
        f"Fragments: {len(detector.fragments)}"
    )
    print(
        "Mode:      FIND ONLY — originals are never modified"
    )
    print("=" * 66)
    print()
    print("Scanning folders...")

    scan_started = time.perf_counter()

    try:
        (
            images,
            skipped_conversion_images,
        ) = discover_images(
            root
        )
    except Exception as exc:
        print(
            f"ERROR scanning folders: {exc}",
            file=sys.stderr,
        )
        return 2

    scan_seconds = (
        time.perf_counter()
        - scan_started
    )

    print(
        f"Found {len(images):,} product image(s)."
    )
    print(
        f"Skipped {skipped_conversion_images:,} "
        "image(s) inside conversions."
    )
    print(
        f"Folder scan: {scan_seconds:.2f}s"
    )
    print()

    if not images:
        print("Nothing to scan.")
        return 0

    results: list[Result] = []

    counters = {
        "eco_trade_found": 0,
        "not_found": 0,
        "error": 0,
    }

    processing_started = (
        time.perf_counter()
    )

    progress = tqdm(
        images,
        total=len(images),
        unit="img",
        dynamic_ncols=True,
        desc="Checking",
    )

    for path in progress:
        result = process_image(
            path=path,
            detector=detector,
            use_ocr=args.ocr,
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
            found=counters[
                "eco_trade_found"
            ],
            clean=counters[
                "not_found"
            ],
            errors=counters[
                "error"
            ],
            refresh=True,
        )

        if result.status == "eco_trade_found":
            try:
                relative = path.relative_to(
                    root
                )
            except ValueError:
                relative = path

            tqdm.write(
                f"FOUND: {relative} "
                f"[{result.detection_method}] "
                f"score={result.best_visual_score:.3f}"
            )

        elif result.status == "error":
            tqdm.write(
                f"ERROR: {path} -> "
                f"{result.message}"
            )

    progress.close()

    processing_seconds = (
        time.perf_counter()
        - processing_started
    )

    report_dir = (
        Path(args.report_dir)
        if args.report_dir
        else root / "watermark-reports"
    )

    (
        csv_path,
        json_path,
        matches_path,
    ) = create_report_paths(
        report_dir
    )

    summary = {
        "root": str(root),
        "reference": str(reference_path),
        "ocr_enabled": bool(args.ocr),
        "reference_fragments": len(
            detector.fragments
        ),
        "images_checked": len(images),
        "eco_trade_found": counters[
            "eco_trade_found"
        ],
        "not_found": counters[
            "not_found"
        ],
        "errors": counters[
            "error"
        ],
        "skipped_conversion_images": (
            skipped_conversion_images
        ),
        "scan_seconds": round(
            scan_seconds,
            3,
        ),
        "processing_seconds": round(
            processing_seconds,
            3,
        ),
        "average_seconds_per_image": round(
            processing_seconds
            / max(1, len(images)),
            4,
        ),
        "finished_at": datetime.now().isoformat(
            timespec="seconds"
        ),
    }

    try:
        write_reports(
            results=results,
            summary=summary,
            csv_path=csv_path,
            json_path=json_path,
            matches_path=matches_path,
        )
    except Exception as exc:
        print(
            f"WARNING: Could not write reports: {exc}",
            file=sys.stderr,
        )

    copied = 0
    copy_errors = 0

    if args.copy_matches:
        copied, copy_errors = copy_matches(
            root=root,
            results=results,
            destination_root=Path(
                args.copy_matches
            ),
        )

    print()
    print("=" * 66)
    print("ECO TRADE WATERMARK SUMMARY")
    print("=" * 66)
    print(
        f"Images checked:               {len(images):,}"
    )
    print(
        f"Eco Trade found:              "
        f"{counters['eco_trade_found']:,}"
    )
    print(
        f"No Eco Trade detected:        "
        f"{counters['not_found']:,}"
    )
    print(
        f"Skipped (conversions):        "
        f"{skipped_conversion_images:,}"
    )
    print(
        f"Errors:                       "
        f"{counters['error']:,}"
    )
    print(
        f"Processing time:              "
        f"{processing_seconds:.2f}s"
    )
    print(
        f"Average/image:                "
        f"{processing_seconds / max(1, len(images)):.3f}s"
    )

    if args.copy_matches:
        print(
            f"Matches copied for review:    {copied:,}"
        )
        print(
            f"Copy errors:                  {copy_errors:,}"
        )

    print()
    print(f"CSV report:   {csv_path}")
    print(f"JSON report:  {json_path}")
    print(f"Matches list: {matches_path}")
    print("=" * 66)

    return (
        1
        if counters["error"] > 0
        else 0
    )


if __name__ == "__main__":
    raise SystemExit(
        main()
    )
