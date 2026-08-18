#!/usr/bin/env python3
r"""Eco Trade removal + Maik Cat replacement, v7.

The watermark is a white overlay anchored to the canvas, so removal is an algebraic
inverse rather than inpainting and verification is a number rather than a guess; see
``ecotrade_watermark.py`` for the model.

Four things this gets right that earlier runs did not, each of which on its own left
the mark plainly visible:

* **An existing Maik Cat mark does not mean the image is done.** Earlier runs stamped
  the whole library while leaving Eco Trade behind, so skipping stamped images skipped
  6,687 of 6,967 without examining them. The stamp now suppresses only re-stamping;
  Eco Trade is measured either way, on a copy with the stamp taken off so it cannot
  bias the measurement.

* **Ink strength is not fitted to stroke-outline sharpness.** Over-correcting drives
  the stroke interior to clipped black, which is flat, so that objective keeps
  improving well past the point where the mark has become a visible dark ghost - its
  optimum is a damaged image. Strength now comes from the opacity measured at each
  coverage level, which has no such failure mode.

* **Acceptance is judged on the mark, in grey levels.** ``trace_amplitude`` measures
  how much watermark-shaped signal is left, with the stroke's interior and outline
  checked separately so one cannot cancel the other. The old test allowed a residual
  of ~29 grey levels and passed images that still showed the mark.

* **The stroke contour is repaired, not left.** A 1-2 px rim survives any inverse -
  closing it needs this image's own coverage to within a percent, which a shared
  profile does not carry - and a thin coherent contour is the most visible artefact
  there is, however small its effect on any average.

Usage:
    # look, change nothing
    py scripts/ecotrade_remove_and_replace_v7.py --root <dir> --audit

    # write results elsewhere, with before/after strips to review
    py scripts/ecotrade_remove_and_replace_v7.py --root <dir> --output <dir> --preview-dir <dir>

    # overwrite in place, once the previews look right
    py scripts/ecotrade_remove_and_replace_v7.py --root <dir> --in-place

Images already flattened and stamped by an earlier run have lost the alpha channel
that records the ink exactly. Restore those masters from the backup archive with
``ecotrade_restore_originals.py`` before running, or removal works from the degraded
copy and cannot do as well.
"""
from __future__ import annotations

import argparse
import csv
import json
import os
import sys
import time
from concurrent.futures import ProcessPoolExecutor, as_completed
from dataclasses import asdict, dataclass
from datetime import datetime
from pathlib import Path

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parent))
from ecotrade_watermark import (  # noqa: E402
    apply_maik, eco_coverage, eco_tile, fit_eco, flatten_white,
    load_eco_profile, load_maik_tile, edge_energy, maik_present,
    maik_scale_for, object_mask, out_of_gamut, polish_residual, read_rgba, remove_eco,
    remove_maik, repair_unexplained, soften_edges, trace_amplitude, unexplained_band,
    unblend, write_image, zone_residuals,
)

IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".webp", ".bmp", ".tif", ".tiff"}
SKIP_DIRS = {"conversions", "watermark-reports", "ecotrade-matches", "previews"}

# Grey levels of watermark-shaped signal still allowed in an accepted result, measured
# by `trace_amplitude`.  An untouched mark scores 80-plus and a genuinely clean image a
# couple of levels, so this sits just above the noise: anything above it is a mark you
# can still see, and is reported rather than passed off as removed.
RESIDUAL_LIMIT = 3.0

_CTX: dict = {}


@dataclass
class Result:
    path: str
    status: str = ""
    canvas: str = ""
    evidence: str = "none"
    strength: float = 0.0
    offset: str = ""
    alpha_ink_px: int = 0
    alpha_match: float = 0.0
    amp_before: float = 0.0
    amp_after: float = 0.0
    interior_after: float = 0.0
    transition_after: float = 0.0
    amp_sigma: float = 0.0
    passes: int = 0
    alpha_curve: str = ""
    lift_ratio_before: float = 0.0
    edge_before: float = 0.0
    edge_after: float = 0.0
    repaired_px: int = 0
    ink_px: int = 0
    maik_before: float = 0.0
    maik_kept: bool = False
    maik_scale: float = 0.0
    output: str = ""
    message: str = ""
    seconds: float = 0.0


# ---------------------------------------------------------------------------
def discover(root: Path, match: str | None = None) -> tuple[list[Path], int, int]:
    """Collect candidate images, skipping generated conversion folders.

    ``match`` keeps only filenames containing that substring - use it to point an
    in-place run at the Eco Trade masters instead of the whole media library.
    """
    images: list[Path] = []
    skipped = 0
    filtered = 0
    for current, dirs, files in os.walk(root):
        here = Path(current)
        keep = []
        for name in dirs:
            if name.lower() in SKIP_DIRS:
                if name.lower() == "conversions":
                    try:
                        skipped += sum(1 for p in (here / name).rglob("*")
                                       if p.is_file() and p.suffix.lower() in IMAGE_EXTS)
                    except OSError:
                        pass
            else:
                keep.append(name)
        dirs[:] = keep
        for name in files:
            p = here / name
            if p.suffix.lower() not in IMAGE_EXTS:
                continue
            if match and match.lower() not in name.lower():
                filtered += 1
                continue
            images.append(p)
    return images, skipped, filtered


def order(images: list[Path], root: Path, descending: bool, start_folder: str | None) -> list[Path]:
    grouped: dict[str, list[Path]] = {}
    for p in images:
        try:
            parts = p.relative_to(root).parts
            key = parts[0] if len(parts) > 1 else ""
        except ValueError:
            key = ""
        grouped.setdefault(key, []).append(p)

    def folder_key(name: str):
        return (0, int(name)) if name.isdigit() else (1, name.lower())

    folders = sorted(grouped, key=folder_key, reverse=descending)
    if start_folder and start_folder in folders:
        i = folders.index(start_folder)
        folders = folders[i:] + folders[:i]
    out: list[Path] = []
    for f in folders:
        out.extend(sorted(grouped[f], key=lambda p: str(p).lower()))
    return out


# ---------------------------------------------------------------------------
def init_worker(profile_path: str | None, maik_path: str | None, opts: dict) -> None:
    cv2.setNumThreads(1)                      # workers must not fight over cores
    profile = load_eco_profile(Path(profile_path) if profile_path else None)
    _CTX["profile"] = profile
    _CTX["tile"] = eco_tile(profile)
    _CTX["maik"] = load_maik_tile(Path(maik_path) if maik_path else None)
    _CTX["opts"] = opts


def preview_strip(before: np.ndarray, cleaned: np.ndarray, final: np.ndarray) -> np.ndarray:
    h = before.shape[0]
    gap = np.full((h, 6, 3), 40, np.uint8)
    strip = np.hstack([before, gap, cleaned, gap, final])
    k = 3 if strip.shape[1] < 1600 else 2 if strip.shape[1] < 2600 else 1
    if k > 1:
        strip = cv2.resize(strip, (strip.shape[1] * k, h * k), interpolation=cv2.INTER_CUBIC)
    return strip


def process(src: Path, dst: Path, preview: Path | None) -> Result:
    started = time.perf_counter()
    r = Result(path=str(src))
    o = _CTX["opts"]
    try:
        rgba = read_rgba(src)
        if rgba is None:
            r.status = "error"
            r.message = "Could not read image."
            return r
        h, w = rgba.shape[:2]
        r.canvas = f"{w}x{h}"
        profile = _CTX["profile"]
        tile = _CTX["tile"]
        maik = _CTX["maik"]
        scale = maik_scale_for(w, o["maik_scale_mode"])
        r.maik_scale = round(scale, 4)

        before = flatten_white(rgba)
        obj = object_mask(rgba)
        r.maik_before = round(maik_present(before, maik, scale), 3)

        # An image that already carries the Maik Cat mark is *not* skipped.  Earlier
        # runs stamped the whole library while leaving Eco Trade behind, so treating a
        # Maik Cat mark as "already done" is what left the mark on nearly every image -
        # 6,687 of 6,967 in the last run were never even examined.  The stamp only means
        # do not stamp again; it says nothing about Eco Trade.
        r.maik_kept = r.maik_before >= o["maik_present_threshold"] and not o["force"]

        # Measuring has to happen on an image without the Maik Cat lattice, because that
        # lattice contaminates the un-inked neighbours Eco Trade's opacity is compared
        # against.  This copy is measured only; whatever is written keeps its own stamp.
        if r.maik_kept:
            work = np.dstack([remove_maik(rgba[:, :, :3], maik, scale), rgba[:, :, 3]])
        else:
            work = rgba
        measure = flatten_white(work)

        fit = fit_eco(rgba, profile, tile=tile, bgr=measure, search=o["search"],
                      min_amp=o["min_amp"], min_amp_sigma=o["min_amp_sigma"],
                      min_coherence=o["min_coherence"])
        r.evidence = fit.evidence
        r.strength = round(fit.strength, 1)
        r.offset = f"({fit.dx},{fit.dy})"
        r.alpha_ink_px = fit.alpha_ink_px
        r.alpha_match = round(fit.alpha_match, 3)
        r.amp_before = round(fit.amp, 2)
        r.amp_sigma = round(fit.amp_sigma, 1)
        r.lift_ratio_before = round(fit.lift_ratio, 3)
        r.ink_px = fit.ink_px

        if not fit.detected:
            if fit.evidence == "band_too_small":
                # Something matched, but only a strip of this canvas is modelled, so it
                # cannot be confirmed. Flagged for a human rather than acted on.
                r.status = "possible_ecotrade_unconfirmed"
                r.message = (f"{fit.amp:.0f} grey levels of lattice-shaped signal found, "
                             f"but canvas {w}x{h} is taller than the band the profile "
                             f"describes, so it could not be confirmed; left untouched.")
                return r
            if r.maik_kept:
                r.status = "no_ecotrade_already_maik"
                r.message = ("No Eco Trade watermark found; the Maik Cat mark is "
                             "already there, so nothing to do.")
            elif o["stamp_clean"]:
                final = apply_maik(before, maik, scale)
                if not o["audit"]:
                    write_image(dst, final)
                    r.output = str(dst)
                r.status = "clean_watermarked"
                r.message = "No Eco Trade watermark found; Maik Cat applied."
            else:
                r.status = "no_ecotrade"
                r.message = "No Eco Trade watermark found; left untouched."
            return r

        coverage = eco_coverage(h, w, tile, fit.dx, fit.dy)
        cleaned_work, amap, curve, amp0, _, passes = remove_eco(
            work, coverage, obj, np.array(fit.curve, dtype=np.float64),
            passes=o["passes"], accept_amp=o["residual_limit"])
        r.passes = passes
        r.strength = round(float(curve[-1] * 255.0), 1)
        r.alpha_curve = " ".join("%.3f" % v for v in curve)

        # The alpha map was solved on the un-stamped copy; applying it to the image the
        # caller keeps is exact, because un-blending depends only on the map.
        cleaned_rgba = unblend(rgba, amap) if r.maik_kept else cleaned_work
        if o["soften"]:
            cleaned_rgba = soften_edges(cleaned_rgba, amap)
        cleaned = flatten_white(cleaned_rgba)
        verify = flatten_white(cleaned_work) if r.maik_kept else cleaned
        if o["polish"]:
            cleaned = polish_residual(cleaned, coverage, obj)
            verify = polish_residual(verify, coverage, obj) if r.maik_kept else cleaned

        # Repairing the stroke contour is part of the normal path, not an extra.  The
        # inverse cannot close that rim - it needs per-image coverage the shared profile
        # does not have - and the rim is the part you can actually still see.  It is a
        # 1-2 px band on the mark's own contour, so filling it costs no detail worth
        # keeping, and skipping it is what left a traceable outline behind before.
        if o["repair"]:
            band = unexplained_band(verify, coverage, obj, tol=o["repair_threshold"])
            repair_mask = band | out_of_gamut(rgba, amap)
            r.repaired_px = int(repair_mask.sum())
            if repair_mask.any():
                cleaned = repair_unexplained(cleaned, repair_mask, radius=4, obj=obj)
                verify = (repair_unexplained(verify, repair_mask, radius=4, obj=obj)
                          if r.maik_kept else cleaned)

        amp_after = trace_amplitude(verify, coverage, obj)[0]
        interior, transition = zone_residuals(verify, coverage, obj)
        worst = max(abs(amp_after), abs(interior), abs(transition))
        r.amp_after = round(float(amp_after), 2)
        r.interior_after = round(float(interior), 2)
        r.transition_after = round(float(transition), 2)
        e_before = edge_energy(measure, coverage, obj)
        e_after = edge_energy(verify, coverage, obj)
        r.edge_before = round(float(e_before), 3) if np.isfinite(e_before) else 0.0
        r.edge_after = round(float(e_after), 3) if np.isfinite(e_after) else 0.0

        # Acceptance is judged on how much lattice-shaped signal is left, in grey levels,
        # with the stroke's interior and its outline checked separately so one cannot
        # hide the other.  The previous test scored stroke-outline sharpness, which
        # over-correction *lowers* by crushing the stroke to black - so it accepted
        # results that plainly still showed the mark.
        imperfect = worst > o["residual_limit"]
        if imperfect and o["strict_residual"]:
            r.status = "residual_left"
            r.message = (f"Watermark cut from {amp0:.1f} to {amp_after:+.1f} grey levels "
                         f"(interior {interior:+.1f}, outline {transition:+.1f}) but the "
                         f"limit is {o['residual_limit']:.1f}; original not overwritten.")
            if preview is not None:
                write_image(preview, preview_strip(before, cleaned,
                                                   apply_maik(cleaned, maik, scale)))
            return r

        if r.maik_kept or o["skip_maik"]:
            final = cleaned
        else:
            final = apply_maik(cleaned, maik, scale)
        if o["keep_alpha"]:
            out_img = np.dstack([final, cleaned_rgba[:, :, 3]])
        else:
            out_img = final

        if not o["audit"]:
            write_image(dst, out_img)
            r.output = str(dst)
        if preview is not None:
            write_image(preview, preview_strip(before, cleaned, final))
        if imperfect:
            # A partial removal still beats leaving the full watermark in place, so the
            # result is written - but it is reported separately so it can be reviewed.
            r.status = "removed_with_residual"
            r.message = (f"Written, but {worst:.1f} grey levels of mark remain "
                         f"(amp {amp_after:+.1f}, interior {interior:+.1f}, outline "
                         f"{transition:+.1f}; limit {o['residual_limit']:.1f}).")
        else:
            r.status = "removed_and_replaced"
            if r.maik_kept:
                r.message = "Eco Trade removed; existing Maik Cat mark left as it was."
        return r
    except Exception as exc:                                  # noqa: BLE001
        r.status = "error"
        r.message = f"{type(exc).__name__}: {exc}"
        return r
    finally:
        r.seconds = round(time.perf_counter() - started, 4)


# ---------------------------------------------------------------------------
def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Eco Trade removal + Maik Cat replacement v7")
    p.add_argument("--root", required=True, help="Directory to scan")
    p.add_argument("--in-place", action="store_true", help="Overwrite the source images")
    p.add_argument("--output", help="Write results into this directory instead")
    p.add_argument("--audit", action="store_true", help="Measure and report, write nothing")
    p.add_argument("--preview-dir", help="Write before / cleaned / final strips here")
    p.add_argument("--eco-profile", help="Override the Eco Trade profile asset")
    p.add_argument("--maik-tile", help="Override the Maik Cat tile asset")
    p.add_argument("--stamp-clean", action="store_true",
                   help="Also apply Maik Cat to images that carry no Eco Trade mark")
    p.add_argument("--skip-maik", action="store_true", help="Remove Eco Trade only")
    p.add_argument("--keep-alpha", action="store_true",
                   help="Keep the transparent background instead of flattening to white")
    p.add_argument("--maik-scale-mode", choices=("auto", "absolute"), default="auto",
                   help="auto scales the mark with image width (matches the catalogue look)")
    p.add_argument("--soften", action="store_true",
                   help="Median-filter the stroke boundary after removal - off by default")
    p.add_argument("--passes", type=int, default=3,
                   help="How many times to re-measure and top up the removal. Each pass "
                        "measures the ink against cleaner neighbours than the last; the "
                        "best result is kept, so extra passes cannot make things worse.")
    p.add_argument("--residual-limit", type=float, default=RESIDUAL_LIMIT,
                   help="Grey levels of watermark-shaped signal an accepted result may "
                        "still carry. An untouched mark measures 80+, a clean image 2-3.")
    p.add_argument("--polish", action="store_true",
                   help="Also flatten leftover offset inside the strokes; edits pixels "
                        "the model cannot account for, so off by default")
    p.add_argument("--no-repair", dest="repair", action="store_false",
                   help="Do not fill the stroke contour the inverse cannot explain. On "
                        "by default: that 1-2 px rim is unrecoverable by inversion and is "
                        "the part of the mark that stays visible.")
    p.add_argument("--repair-threshold", type=float, default=9.0,
                   help="Grey levels a contour pixel must stand out by before it is "
                        "filled (lower = more aggressive, at the cost of detail on the "
                        "stroke contour)")
    p.add_argument("--strict-residual", action="store_true",
                   help="Leave the original untouched when a measurable residual remains, "
                        "instead of writing the partially cleaned result")
    p.add_argument("--force", action="store_true",
                   help="Process even images that already carry the Maik Cat mark")
    p.add_argument("--search", type=int, default=8,
                   help="Alignment search radius in px around the canvas origin. The "
                        "mark is anchored there in every source whose alpha channel "
                        "records it exactly, so this stays small on purpose: searching "
                        "wide finds better-correlating placements on a busy photo than "
                        "the true one, and fits a nonsense opacity to them.")
    p.add_argument("--min-amp", type=float, default=12.0,
                   help="Grey levels of lattice-shaped signal needed to call a mark "
                        "present. A real mark measures 80+ at full strength and ~24 at "
                        "the weakest strength seen; a clean image stays under 13.")
    p.add_argument("--min-amp-sigma", type=float, default=25.0,
                   help="The same in standard errors. Every placement is searched, so "
                        "the winner on a clean image is the maximum of many noisy "
                        "numbers - this is what keeps that from counting as a mark.")
    p.add_argument("--min-coherence", type=float, default=0.35,
                   help="Weakest lattice repeat's share of the whole mark's amplitude. "
                        "Real ink repeats; a lucky alignment on texture does not.")
    p.add_argument("--maik-present-threshold", type=float, default=0.6,
                   help="Guard score above which an image counts as already stamped "
                        "(a real mark scores ~0.65-1.2, an unstamped one below 0.55)")
    p.add_argument("--workers", type=int, default=max(1, min(12, (os.cpu_count() or 4))))
    p.add_argument("--descending", action="store_true")
    p.add_argument("--start-folder")
    p.add_argument("--match", help="Only process files whose name contains this text, "
                                   "e.g. --match ecotrade")
    p.add_argument("--limit", type=int, help="Process at most this many images")
    p.add_argument("--report-dir")
    return p.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(args.root)
    if not root.exists():
        print(f"ERROR: root does not exist: {root}", file=sys.stderr)
        return 2
    if not args.audit and not args.in_place and not args.output:
        print("ERROR: choose --audit, --in-place or --output PATH.", file=sys.stderr)
        return 2

    opts = {
        "audit": args.audit,
        "stamp_clean": args.stamp_clean,
        "skip_maik": args.skip_maik,
        "keep_alpha": args.keep_alpha,
        "maik_scale_mode": args.maik_scale_mode,
        "soften": args.soften,
        "passes": args.passes,
        "residual_limit": args.residual_limit,
        "polish": args.polish,
        "repair": args.repair,
        "repair_threshold": args.repair_threshold,
        "strict_residual": args.strict_residual,
        "force": args.force,
        "search": args.search,
        "min_amp": args.min_amp,
        "min_amp_sigma": args.min_amp_sigma,
        "min_coherence": args.min_coherence,
        "maik_present_threshold": args.maik_present_threshold,
    }

    try:
        load_eco_profile(Path(args.eco_profile) if args.eco_profile else None)
        load_maik_tile(Path(args.maik_tile) if args.maik_tile else None)
    except FileNotFoundError as exc:
        print(f"ERROR: {exc}\nRun scripts/ecotrade_learn_watermarks.py first.", file=sys.stderr)
        return 2

    scan_start = time.perf_counter()
    images, skipped, filtered = discover(root, args.match)
    images = order(images, root, args.descending, args.start_folder)
    if args.limit:
        images = images[:args.limit]
    scan_seconds = time.perf_counter() - scan_start

    mode = "AUDIT (no writes)" if args.audit else ("IN PLACE" if args.in_place else "COPY")
    print("\nEco Trade -> Maik Cat  v7")
    print("=" * 78)
    print(f"Root:            {root}")
    print(f"Mode:            {mode}")
    filter_note = f", {filtered:,} not matching '{args.match}'" if args.match else ""
    print(f"Images:          {len(images):,}   "
          f"(skipped {skipped:,} in conversions{filter_note})")
    print(f"Workers:         {args.workers}")
    print(f"Maik scale:      {args.maik_scale_mode}")
    print(f"Scan time:       {scan_seconds:.2f}s")
    print("=" * 78)

    dest_root = root if (args.in_place or args.audit) else Path(args.output)
    preview_root = Path(args.preview_dir) if args.preview_dir else None
    results: list[Result] = []
    started = time.perf_counter()

    with ProcessPoolExecutor(max_workers=max(1, args.workers), initializer=init_worker,
                             initargs=(args.eco_profile, args.maik_tile, opts)) as pool:
        futures = {}
        for src in images:
            try:
                rel = src.relative_to(root)
            except ValueError:
                rel = Path(src.name)
            dst = src if (args.in_place or args.audit) else dest_root / rel
            prev = None
            if preview_root is not None:
                prev = preview_root / rel.with_suffix(".png").as_posix().replace("/", "__")
            futures[pool.submit(process, src, dst, prev)] = src

        done = 0
        for fut in as_completed(futures):
            r = fut.result()
            results.append(r)
            done += 1
            if done % 25 == 0 or done == len(images):
                rate = done / max(1e-6, time.perf_counter() - started)
                print(f"  {done}/{len(images)}  ({rate:.1f} img/s)", flush=True)
            if r.status in {"error", "residual_left", "removed_with_residual"}:
                print(f"  {r.status.upper()}: {r.path} -> {r.message}", flush=True)

    elapsed = time.perf_counter() - started
    results.sort(key=lambda x: x.path.lower())
    counts: dict[str, int] = {}
    for r in results:
        counts[r.status] = counts.get(r.status, 0) + 1

    report_dir = Path(args.report_dir) if args.report_dir else root / "watermark-reports"
    report_dir.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    csv_path = report_dir / f"ecotrade_v7_{stamp}.csv"
    json_path = report_dir / f"ecotrade_v7_{stamp}.json"
    fields = list(asdict(Result(path="")).keys())
    with csv_path.open("w", newline="", encoding="utf-8-sig") as fh:
        wtr = csv.DictWriter(fh, fieldnames=fields)
        wtr.writeheader()
        for r in results:
            wtr.writerow(asdict(r))
    summary = {
        "root": str(root), "mode": mode, "images": len(images),
        "match": args.match, "skipped_conversions": skipped,
        "skipped_not_matching": filtered, "workers": args.workers,
        "counts": counts, "seconds": round(elapsed, 2),
        "images_per_second": round(len(images) / max(1e-6, elapsed), 2),
        "finished_at": datetime.now().isoformat(timespec="seconds"),
    }
    with json_path.open("w", encoding="utf-8") as fh:
        json.dump({"summary": summary, "results": [asdict(r) for r in results]},
                  fh, ensure_ascii=False, indent=2)

    print("\n" + "=" * 78)
    print("SUMMARY")
    for k in sorted(counts):
        print(f"  {k:<24} {counts[k]:,}")
    print(f"  {'time':<24} {elapsed:.2f}s  ({len(images) / max(1e-6, elapsed):.1f} img/s)")
    print(f"  {'csv':<24} {csv_path}")
    print(f"  {'json':<24} {json_path}")
    if preview_root is not None:
        print(f"  {'previews':<24} {preview_root}")
    print("=" * 78)
    return 1 if counts.get("error") else 0


if __name__ == "__main__":
    raise SystemExit(main())
