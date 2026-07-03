from __future__ import annotations

import argparse
import csv
import json
import math
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import cv2
import numpy as np

SUPPORTED_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp", ".avif"}


@dataclass(slots=True)
class SpamReference:
    path: Path
    phash: np.ndarray


@dataclass(slots=True)
class WrongWatermarkReference:
    path: Path
    template: np.ndarray


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Scan media images for non-product spam and wrong-watermark candidates.",
    )
    parser.add_argument("--manifest", required=True, help="JSON manifest produced by the Laravel command")
    parser.add_argument("--output-dir", required=True, help="Directory where reports will be written")
    parser.add_argument("--spam-reference", action="append", default=[], help="Reference image for non-product spam")
    parser.add_argument(
        "--wrong-watermark-reference",
        action="append",
        default=[],
        help="Reference image that clearly contains the wrong watermark pattern",
    )
    parser.add_argument("--limit", type=int, default=None, help="Maximum manifest rows to process")
    return parser.parse_args()


def read_image(path: Path) -> np.ndarray | None:
    return cv2.imread(str(path), cv2.IMREAD_UNCHANGED)


def downsample(image: np.ndarray, max_side: int = 256) -> np.ndarray:
    height, width = image.shape[:2]
    scale = max_side / float(max(height, width))
    if scale >= 1.0:
        return image

    return cv2.resize(
        image,
        (max(1, int(round(width * scale))), max(1, int(round(height * scale)))),
        interpolation=cv2.INTER_AREA,
    )


def to_bgr(image: np.ndarray) -> np.ndarray:
    if image.ndim == 2:
        return cv2.cvtColor(image, cv2.COLOR_GRAY2BGR)

    if image.shape[2] == 4:
        return cv2.cvtColor(image, cv2.COLOR_BGRA2BGR)

    return image


def to_gray(image: np.ndarray) -> np.ndarray:
    return cv2.cvtColor(to_bgr(image), cv2.COLOR_BGR2GRAY)


def phash_bits(image: np.ndarray) -> np.ndarray:
    gray = to_gray(downsample(image, 256))
    resized = cv2.resize(gray, (32, 32), interpolation=cv2.INTER_AREA)
    dct = cv2.dct(np.float32(resized))[:8, :8]
    median = np.median(dct[1:, :])
    return (dct > median).flatten()


def hamming_distance(left: np.ndarray, right: np.ndarray) -> int:
    return int(np.count_nonzero(left != right))


def compute_colorfulness(image: np.ndarray) -> float:
    bgr = to_bgr(image).astype(np.float32)
    rg = np.abs(bgr[:, :, 2] - bgr[:, :, 1])
    yb = np.abs(((bgr[:, :, 2] + bgr[:, :, 1]) * 0.5) - bgr[:, :, 0])
    return float(
        math.sqrt(float(rg.std() ** 2 + yb.std() ** 2))
        + (0.3 * math.sqrt(float(rg.mean() ** 2 + yb.mean() ** 2)))
    )


def compute_mean_saturation(image: np.ndarray) -> float:
    hsv = cv2.cvtColor(to_bgr(image), cv2.COLOR_BGR2HSV)
    return float(hsv[:, :, 1].mean())


def detect_faces(image: np.ndarray) -> int:
    cascade_path = Path(cv2.data.haarcascades) / "haarcascade_frontalface_default.xml"
    if not cascade_path.exists():
        return 0

    cascade = cv2.CascadeClassifier(str(cascade_path))
    gray = to_gray(image)
    faces = cascade.detectMultiScale(gray, scaleFactor=1.05, minNeighbors=3, minSize=(20, 20))
    return int(len(faces))


def build_wrong_watermark_template(reference_path: Path) -> np.ndarray:
    image = read_image(reference_path)
    if image is None:
        raise RuntimeError(f"Unable to read wrong-watermark reference: {reference_path}")

    gray = to_gray(image)
    height, width = gray.shape[:2]
    top = int(height * 0.30)
    bottom = int(height * 0.85)
    left = int(width * 0.25)
    right = int(width * 0.80)
    crop = gray[top:bottom, left:right]
    edges = cv2.Canny(crop, 50, 150)

    return edges


def wrong_watermark_match_score(image: np.ndarray, template: np.ndarray) -> float:
    gray = to_gray(image)
    edges = cv2.Canny(gray, 50, 150)
    template_height, template_width = template.shape[:2]
    image_height, image_width = edges.shape[:2]

    if template_height >= image_height or template_width >= image_width:
        scale = min((image_width - 1) / template_width, (image_height - 1) / template_height, 0.95)
        if scale <= 0:
            return 0.0

        template = cv2.resize(
            template,
            (max(1, int(template_width * scale)), max(1, int(template_height * scale))),
            interpolation=cv2.INTER_AREA,
        )

    result = cv2.matchTemplate(edges, template, cv2.TM_CCOEFF_NORMED)
    return float(result.max()) if result.size else 0.0


def make_contact_sheet(image_paths: list[Path], output_path: Path) -> None:
    if not image_paths:
        return

    thumbs: list[np.ndarray] = []
    for path in image_paths[:16]:
        image = read_image(path)
        if image is None:
            continue

        thumb = cv2.resize(to_bgr(image), (220, 160), interpolation=cv2.INTER_AREA)
        cv2.putText(
            thumb,
            path.parent.name,
            (8, 20),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.55,
            (0, 0, 255),
            2,
            cv2.LINE_AA,
        )
        thumbs.append(thumb)

    if not thumbs:
        return

    while len(thumbs) % 4 != 0:
        thumbs.append(np.full((160, 220, 3), 255, dtype=np.uint8))

    rows: list[np.ndarray] = []
    for index in range(0, len(thumbs), 4):
        rows.append(np.hstack(thumbs[index:index + 4]))

    canvas = np.vstack(rows)
    cv2.imwrite(str(output_path), canvas)


def write_csv(path: Path, rows: list[dict[str, Any]]) -> None:
    if not rows:
        headers = [
            "media_id",
            "model_id",
            "file_name",
            "absolute_path",
            "reason",
            "score",
        ]
    else:
        headers = list(rows[0].keys())

    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers)
        writer.writeheader()
        writer.writerows(rows)


def main() -> int:
    args = parse_args()
    manifest_path = Path(args.manifest)
    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    entries: list[dict[str, Any]] = manifest if isinstance(manifest, list) else []
    if args.limit is not None:
        entries = entries[: max(0, args.limit)]

    spam_references: list[SpamReference] = []
    for value in args.spam_reference:
        path = Path(value)
        image = read_image(path)
        if image is None:
            continue
        spam_references.append(SpamReference(path=path, phash=phash_bits(image)))

    wrong_watermark_references: list[WrongWatermarkReference] = []
    for value in args.wrong_watermark_reference:
        path = Path(value)
        if not path.exists():
            continue
        wrong_watermark_references.append(
            WrongWatermarkReference(path=path, template=build_wrong_watermark_template(path))
        )

    spam_rows: list[dict[str, Any]] = []
    wrong_rows: list[dict[str, Any]] = []
    spam_preview_paths: list[Path] = []
    wrong_preview_paths: list[Path] = []

    for entry in entries:
        absolute_path = Path(str(entry.get("absolute_path", "")))
        if absolute_path.suffix.lower() not in SUPPORTED_EXTENSIONS:
            continue

        image = read_image(absolute_path)
        if image is None:
            continue

        preview = downsample(image, 256)
        image_phash = phash_bits(preview)
        colorfulness = compute_colorfulness(preview)
        mean_saturation = compute_mean_saturation(preview)
        face_count = detect_faces(preview)

        spam_distances = [
            hamming_distance(image_phash, reference.phash) for reference in spam_references
        ]
        closest_spam_distance = min(spam_distances) if spam_distances else None
        is_spam_by_hash = closest_spam_distance is not None and closest_spam_distance <= 4
        is_spam_by_face = face_count > 0 and colorfulness >= 35.0 and mean_saturation >= 18.0

        if is_spam_by_hash or is_spam_by_face:
            reason = (
                f"Matched spam reference with pHash distance {closest_spam_distance}"
                if is_spam_by_hash
                else f"Detected {face_count} face-like region(s) in a colorful illustration"
            )
            score = (
                round(1.0 - (closest_spam_distance / 64.0), 4)
                if is_spam_by_hash and closest_spam_distance is not None
                else round(min(1.0, 0.4 + (0.1 * face_count) + (colorfulness / 100.0)), 4)
            )
            spam_rows.append(
                {
                    "media_id": entry["media_id"],
                    "model_id": entry["model_id"],
                    "file_name": entry["file_name"],
                    "absolute_path": str(absolute_path),
                    "reason": reason,
                    "score": score,
                }
            )
            spam_preview_paths.append(absolute_path)
            continue

        if wrong_watermark_references:
            match_scores = [
                wrong_watermark_match_score(image, reference.template)
                for reference in wrong_watermark_references
            ]
            best_match_score = max(match_scores) if match_scores else 0.0
            if best_match_score >= 0.15 and colorfulness >= 20.0:
                wrong_rows.append(
                    {
                        "media_id": entry["media_id"],
                        "model_id": entry["model_id"],
                        "file_name": entry["file_name"],
                        "absolute_path": str(absolute_path),
                        "reason": "Matched wrong-watermark reference template",
                        "match_score": round(best_match_score, 4),
                        "colorfulness": round(colorfulness, 4),
                        "mean_saturation": round(mean_saturation, 4),
                    }
                )
                wrong_preview_paths.append(absolute_path)

    spam_rows.sort(key=lambda row: float(row["score"]), reverse=True)
    wrong_rows.sort(key=lambda row: float(row["match_score"]), reverse=True)

    spam_csv = output_dir / "spam_images.csv"
    wrong_csv = output_dir / "wrong_watermark_images.csv"
    spam_json = output_dir / "spam_images.json"
    wrong_json = output_dir / "wrong_watermark_images.json"
    summary_json = output_dir / "summary.json"
    spam_contact_sheet = output_dir / "spam_preview.jpg"
    wrong_contact_sheet = output_dir / "wrong_watermark_preview.jpg"

    write_csv(spam_csv, spam_rows)
    write_csv(wrong_csv, wrong_rows)
    spam_json.write_text(json.dumps(spam_rows, indent=2), encoding="utf-8")
    wrong_json.write_text(json.dumps(wrong_rows, indent=2), encoding="utf-8")
    make_contact_sheet(spam_preview_paths, spam_contact_sheet)
    make_contact_sheet(wrong_preview_paths, wrong_contact_sheet)

    summary = {
        "entries_scanned": len(entries),
        "spam_count": len(spam_rows),
        "wrong_watermark_count": len(wrong_rows),
        "spam_report_csv": str(spam_csv.resolve()),
        "wrong_watermark_report_csv": str(wrong_csv.resolve()),
        "spam_preview": str(spam_contact_sheet.resolve()) if spam_preview_paths else None,
        "wrong_watermark_preview": str(wrong_contact_sheet.resolve()) if wrong_preview_paths else None,
    }
    summary_json.write_text(json.dumps(summary, indent=2), encoding="utf-8")
    print(json.dumps(summary))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
