#!/usr/bin/env python3
from __future__ import annotations
import argparse, csv, json, math, os, re, shutil, sys, time
from dataclasses import dataclass, asdict
from datetime import datetime
from pathlib import Path
import cv2, numpy as np
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
    detection_method: str | None = None
    template_score: float | None = None
    message: str | None = None
    elapsed_seconds: float = 0.0

def normalize_ocr_text(text: str) -> str:
    return re.sub(r"[^a-z]", "", text.lower())

def rotate_bound(image: np.ndarray, angle: float) -> np.ndarray:
    h, w = image.shape[:2]
    center = (w / 2.0, h / 2.0)
    matrix = cv2.getRotationMatrix2D(center, angle, 1.0)
    cos = abs(matrix[0, 0]); sin = abs(matrix[0, 1])
    new_w = int((h * sin) + (w * cos)); new_h = int((h * cos) + (w * sin))
    matrix[0, 2] += (new_w / 2.0) - center[0]
    matrix[1, 2] += (new_h / 2.0) - center[1]
    return cv2.warpAffine(image, matrix, (new_w, new_h), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_CONSTANT, borderValue=255)

def ocr_has_maik_cat(image_bgr: np.ndarray) -> bool:
    if pytesseract is None:
        raise RuntimeError("pytesseract is not installed.")
    gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
    longest = max(gray.shape[:2]); scale = 2.4 if longest < 1200 else 1.5
    gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
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
    x0 = max(0, x - pad); y0 = max(0, y - pad)
    x1 = min(ref_w, x + w + pad); y1 = min(ref_h, y + h + pad)
    return gray[y0:y1, x0:x1]

def extract_logo_rgba(reference_bgr: np.ndarray) -> np.ndarray:
    gray_logo = extract_one_logo_template(reference_bgr)
    alpha = np.clip((255.0 - gray_logo.astype(np.float32)) / 75.0, 0.0, 1.0)
    rgba = np.zeros((gray_logo.shape[0], gray_logo.shape[1], 4), dtype=np.uint8)
    rgba[:, :, 0] = gray_logo; rgba[:, :, 1] = gray_logo; rgba[:, :, 2] = gray_logo
    rgba[:, :, 3] = (alpha * 255).astype(np.uint8)
    return rgba

class WatermarkDetector:
    def __init__(self, reference_bgr: np.ndarray, template_threshold: float):
        self.reference_h, self.reference_w = reference_bgr.shape[:2]
        self.logo_dark = (255 - extract_one_logo_template(reference_bgr)).astype(np.float32)
        self.template_threshold = template_threshold
    def template_score(self, image_bgr: np.ndarray) -> float:
        target_gray = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
        target_dark = (255 - target_gray).astype(np.float32)
        h, w = image_bgr.shape[:2]
        expected_scale = math.sqrt((w / self.reference_w) * (h / self.reference_h))
        best = -1.0
        for multiplier in (0.70, 0.85, 1.00, 1.15, 1.30):
            scale = expected_scale * multiplier
            tw = max(12, int(round(self.logo_dark.shape[1] * scale)))
            th = max(12, int(round(self.logo_dark.shape[0] * scale)))
            if tw >= w or th >= h: continue
            logo = cv2.resize(self.logo_dark, (tw, th), interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC)
            score = float(np.max(cv2.matchTemplate(target_dark, logo, cv2.TM_CCOEFF_NORMED)))
            best = max(best, score)
        return best

def discover_images(root: Path) -> tuple[list[Path], int]:
    images = []; skipped_conversion_images = 0
    for current_dir, dir_names, file_names in os.walk(root):
        current_path = Path(current_dir); kept_dirs = []
        for d in dir_names:
            if d.lower() in SKIP_DIRS:
                if d.lower() == "conversions":
                    try:
                        skipped_conversion_images += sum(1 for p in (current_path / d).rglob("*") if p.is_file() and p.suffix.lower() in IMAGE_EXTS)
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

def estimate_background_color(image_bgr: np.ndarray) -> np.ndarray:
    h, w = image_bgr.shape[:2]; s = max(3, min(h, w) // 12)
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
    out = bgr.copy(); out[bg_mask > 0] = 255
    return out

def overlay_rgba(dst_bgr: np.ndarray, src_rgba: np.ndarray, x: int, y: int, opacity: float) -> None:
    h, w = dst_bgr.shape[:2]; sh, sw = src_rgba.shape[:2]
    if x >= w or y >= h or x + sw <= 0 or y + sh <= 0: return
    x0 = max(0, x); y0 = max(0, y); x1 = min(w, x + sw); y1 = min(h, y + sh)
    sx0 = x0 - x; sy0 = y0 - y; sx1 = sx0 + (x1 - x0); sy1 = sy0 + (y1 - y0)
    src_crop = src_rgba[sy0:sy1, sx0:sx1]; dst_crop = dst_bgr[y0:y1, x0:x1]
    alpha = (src_crop[:, :, 3].astype(np.float32) / 255.0) * opacity
    alpha = alpha[:, :, None]
    blended = dst_crop.astype(np.float32) * (1.0 - alpha) + src_crop[:, :, :3].astype(np.float32) * alpha
    dst_bgr[y0:y1, x0:x1] = np.clip(blended, 0, 255).astype(np.uint8)

def apply_maik_pattern(image_bgr: np.ndarray, logo_rgba: np.ndarray, logo_width: int = 180, gap_x: int = 290, gap_y: int = 190, opacity: float = 0.34) -> np.ndarray:
    out = image_bgr.copy(); h, w = out.shape[:2]
    target_width = int(min(max(90, logo_width), max(90, w * 0.42)))
    scale = target_width / logo_rgba.shape[1]
    target_height = max(30, int(round(logo_rgba.shape[0] * scale)))
    resized_logo = cv2.resize(logo_rgba, (target_width, target_height), interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC)
    step_x = max(target_width + 35, gap_x); step_y = max(int(target_height * 1.05), gap_y); stagger = step_x // 2
    y = -target_height // 3; row = 0
    while y < h + target_height:
        x = -target_width // 3 + (stagger if row % 2 else 0)
        while x < w + target_width:
            overlay_rgba(out, resized_logo, x, y, opacity); x += step_x
        y += step_y; row += 1
    return out

def save_image(path: Path, image: np.ndarray) -> None:
    path.parent.mkdir(parents=True, exist_ok=True); ext = path.suffix.lower()
    if ext in {".jpg", ".jpeg"}:
        ok = cv2.imwrite(str(path), image, [cv2.IMWRITE_JPEG_QUALITY, 98])
    elif ext == ".png":
        ok = cv2.imwrite(str(path), image, [cv2.IMWRITE_PNG_COMPRESSION, 3])
    elif ext == ".webp":
        ok = cv2.imwrite(str(path), image, [cv2.IMWRITE_WEBP_QUALITY, 100])
    else:
        ok = cv2.imwrite(str(path), image)
    if not ok: raise RuntimeError(f"Could not save: {path}")

def copy_unchanged(source: Path, destination: Path) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True); shutil.copy2(source, destination)

def process_image(path: Path, destination: Path, detector: WatermarkDetector, logo_rgba: np.ndarray, use_ocr: bool, dry_run: bool, force_replace: bool, logo_width: int, gap_x: int, gap_y: int, opacity: float) -> Result:
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
        score = detector.template_score(detection_bgr); detected = score >= detector.template_threshold; method = "template" if detected else None
        if not detected and use_ocr:
            detected = ocr_has_maik_cat(detection_bgr)
            if detected: method = "ocr"
        if detected and not force_replace:
            if not dry_run and path.resolve() != destination.resolve(): copy_unchanged(path, destination)
            return Result(path=str(path), status="already_watermarked", detection_method=method, template_score=score, elapsed_seconds=time.perf_counter() - started)
        if dry_run:
            return Result(path=str(path), status="would_replace", detection_method=("force_replace" if force_replace else "missing_or_restyle"), template_score=score, elapsed_seconds=time.perf_counter() - started)
        white_bg = whiten_background(image)
        styled = apply_maik_pattern(white_bg, logo_rgba=logo_rgba, logo_width=logo_width, gap_x=gap_x, gap_y=gap_y, opacity=opacity)
        save_image(destination, styled)
        return Result(path=str(path), status="replaced", detection_method=("force_replace" if force_replace else "missing_or_restyle"), template_score=score, elapsed_seconds=time.perf_counter() - started)
    except Exception as exc:
        return Result(path=str(path), status="error", message=str(exc), elapsed_seconds=time.perf_counter() - started)

def write_reports(results: list[Result], summary: dict, report_dir: Path) -> tuple[Path, Path]:
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    csv_path = report_dir / f"maik_cat_replace_{timestamp}.csv"; json_path = report_dir / f"maik_cat_replace_{timestamp}.json"
    report_dir.mkdir(parents=True, exist_ok=True)
    with csv_path.open("w", newline="", encoding="utf-8-sig") as handle:
        writer = csv.DictWriter(handle, fieldnames=["path", "status", "detection_method", "template_score", "message", "elapsed_seconds"])
        writer.writeheader()
        for result in results:
            row = asdict(result)
            if row["template_score"] is not None: row["template_score"] = round(row["template_score"], 6)
            row["elapsed_seconds"] = round(row["elapsed_seconds"], 4)
            writer.writerow(row)
    with json_path.open("w", encoding="utf-8") as handle:
        json.dump({"summary": summary, "results": [asdict(r) for r in results]}, handle, ensure_ascii=False, indent=2)
    return csv_path, json_path

def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Replace product images in-place with a clean white background and a larger Maik Cat watermark.")
    parser.add_argument("--root", default=str(DEFAULT_ROOT))
    parser.add_argument("--reference", required=True)
    parser.add_argument("--in-place", action="store_true")
    parser.add_argument("--output")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--ocr", action="store_true")
    parser.add_argument("--tesseract-cmd")
    parser.add_argument("--template-threshold", type=float, default=0.72)
    parser.add_argument("--force-replace", action="store_true", help="Re-style every image even if Maik Cat is already detected.")
    parser.add_argument("--logo-width", type=int, default=180)
    parser.add_argument("--gap-x", type=int, default=290)
    parser.add_argument("--gap-y", type=int, default=190)
    parser.add_argument("--opacity", type=float, default=0.34)
    parser.add_argument("--report-dir")
    return parser.parse_args()

def main() -> int:
    args = parse_args(); root = Path(args.root); reference_path = Path(args.reference)
    if args.tesseract_cmd and pytesseract is not None: pytesseract.pytesseract.tesseract_cmd = args.tesseract_cmd
    if not root.exists():
        print(f"ERROR: Root path not found: {root}", file=sys.stderr); return 2
    if not reference_path.exists():
        print(f"ERROR: Reference image not found: {reference_path}", file=sys.stderr); return 2
    if not args.dry_run and not args.in_place and not args.output:
        print("ERROR: Choose --in-place, --output PATH, or use --dry-run.", file=sys.stderr); return 2
    if args.ocr:
        if pytesseract is None:
            print("ERROR: pytesseract is not installed.", file=sys.stderr); return 2
        try:
            print("Tesseract OCR:", pytesseract.get_tesseract_version())
        except Exception as exc:
            print(f"ERROR using Tesseract: {exc}", file=sys.stderr); return 2
    reference_bgr = cv2.imread(str(reference_path), cv2.IMREAD_COLOR)
    if reference_bgr is None:
        print(f"ERROR: Could not read reference image: {reference_path}", file=sys.stderr); return 2
    detector = WatermarkDetector(reference_bgr=reference_bgr, template_threshold=args.template_threshold)
    logo_rgba = extract_logo_rgba(reference_bgr)
    print("\nMaik Cat watermark replacement v3")
    print("=" * 72)
    print(f"Root:          {root}")
    print(f"Reference:     {reference_path}")
    print("Skip:          every 'conversions' folder")
    print(f"OCR:           {'enabled' if args.ocr else 'disabled'}")
    print(f"Mode:          {'DRY RUN' if args.dry_run else 'REPLACE ORIGINALS IN PLACE' if args.in_place else 'OUTPUT COPY'}")
    print(f"Force replace: {'yes' if args.force_replace else 'no'}")
    print(f"Logo width:    {args.logo_width}")
    print(f"Gap X / Y:     {args.gap_x} / {args.gap_y}")
    print(f"Opacity:       {args.opacity}")
    print("=" * 72 + "\n")
    scan_started = time.perf_counter(); images, skipped_conversion_images = discover_images(root); scan_seconds = time.perf_counter() - scan_started
    print(f"Found {len(images):,} product image(s)."); print(f"Skipped {skipped_conversion_images:,} image(s) inside conversions."); print(f"Folder scan: {scan_seconds:.2f}s\n")
    if not images: print("Nothing to process."); return 0
    destination_root = root if args.in_place or args.dry_run else Path(args.output)
    results: list[Result] = []; counters = {"replaced": 0, "already_watermarked": 0, "would_replace": 0, "error": 0}
    started = time.perf_counter(); progress = tqdm(images, total=len(images), unit="img", dynamic_ncols=True, desc="Checking")
    for source in progress:
        relative = source.relative_to(root); destination = source if args.in_place or args.dry_run else destination_root / relative
        result = process_image(source, destination, detector, logo_rgba, args.ocr, args.dry_run, args.force_replace, args.logo_width, args.gap_x, args.gap_y, args.opacity)
        results.append(result); counters[result.status] = counters.get(result.status, 0) + 1
        progress.set_postfix(replaced=counters["replaced"], exists=counters["already_watermarked"], errors=counters["error"], refresh=True)
        if result.status == "error": tqdm.write(f"ERROR: {relative} -> {result.message}")
    progress.close(); processing_seconds = time.perf_counter() - started
    report_dir = Path(args.report_dir) if args.report_dir else root / "watermark-reports"
    summary = {
        "root": str(root), "reference": str(reference_path),
        "mode": "dry_run" if args.dry_run else "in_place" if args.in_place else "output_copy",
        "ocr_enabled": bool(args.ocr), "force_replace": bool(args.force_replace),
        "logo_width": args.logo_width, "gap_x": args.gap_x, "gap_y": args.gap_y, "opacity": args.opacity,
        "images_checked": len(images), "replaced": counters["replaced"], "already_watermarked": counters["already_watermarked"],
        "would_replace": counters["would_replace"], "errors": counters["error"], "skipped_conversion_images": skipped_conversion_images,
        "scan_seconds": round(scan_seconds, 3), "processing_seconds": round(processing_seconds, 3),
        "average_seconds_per_image": round(processing_seconds / max(1, len(images)), 4),
        "finished_at": datetime.now().isoformat(timespec="seconds"),
    }
    csv_path, json_path = write_reports(results, summary, report_dir)
    print("\n" + "=" * 72); print("MAIK CAT SUMMARY"); print("=" * 72)
    print(f"Images checked:               {len(images):,}")
    print(f"Already watermarked skipped:  {counters['already_watermarked']:,}")
    print(f"{'Would replace' if args.dry_run else 'Replaced in place'}:".ljust(30) + f" {counters['would_replace'] if args.dry_run else counters['replaced']:,}")
    print(f"Skipped (conversions):        {skipped_conversion_images:,}")
    print(f"Errors:                       {counters['error']:,}")
    print(f"Processing time:              {processing_seconds:.2f}s")
    print(f"Average/image:                {processing_seconds / max(1, len(images)):.3f}s\n")
    print(f"CSV report:   {csv_path}"); print(f"JSON report:  {json_path}"); print("=" * 72)
    return 1 if counters["error"] > 0 else 0

if __name__ == "__main__":
    raise SystemExit(main())
