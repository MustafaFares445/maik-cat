#!/usr/bin/env python3
r"""Learn the Eco Trade and Maik Cat watermark assets from real images.

Both watermarks are deterministic overlays anchored to the image canvas, so they can
be measured instead of guessed:

* Eco Trade - on RGBA sources the ink survives in the alpha channel wherever the
  background is transparent.  Taking the per-pixel median across many sources
  recovers the exact coverage map; pixels always hidden behind an object are filled
  from the confirmed 176 px horizontal period, then inpainted.
* Maik Cat - the catalogue images already carry the finished mark, so folding their
  white background over the 320x180 brick lattice recovers the tile exactly.

Run this only when the source watermark changes.  The committed assets in
``scripts/assets`` are the output of a previous run.

Usage:
    py scripts/ecotrade_learn_watermarks.py --eco-source <dir-of-originals>
    py scripts/ecotrade_learn_watermarks.py --maik-source storage/app/public
"""
from __future__ import annotations

import argparse
import collections
from pathlib import Path

import cv2
import numpy as np

from ecotrade_watermark import (
    ASSET_DIR, ECO_PROFILE_ASSET, ECO_PROFILE_SCALE, MAIK_SX, MAIK_TILE_ASSET,
    MAIK_TILE_SCALE, MAIK_TX, MAIK_TY, alpha_evidence, read_rgba,
)

IMAGE_EXTS = {".png", ".jpg", ".jpeg", ".webp"}
H_PERIOD = 176            # confirmed by autocorrelation (r = 0.93)


def iter_images(root: Path):
    for p in sorted(root.rglob("*")):
        if p.is_file() and p.suffix.lower() in IMAGE_EXTS:
            yield p


# ---------------------------------------------------------------------------
def learn_eco_profile(source: Path, min_images: int = 8) -> tuple[np.ndarray, dict]:
    """Per-pixel coverage map, learned from the alpha channel of RGBA originals."""
    groups: dict[tuple, list[np.ndarray]] = collections.defaultdict(list)
    strengths: dict[tuple, list[float]] = collections.defaultdict(list)

    for p in iter_images(source):
        rgba = read_rgba(p)
        if rgba is None:
            continue
        obs, valid = alpha_evidence(rgba)
        band = valid & (obs > 8) & (obs < 250)
        if int(band.sum()) < 300:
            continue
        k = float(np.median(obs[band]))
        if not 8.0 <= k <= 220.0:
            continue
        # normalise each source by its own ink strength so 38- and 129-strength
        # sources can be merged into one coverage map
        groups[rgba.shape[:2]].append(np.where(valid, obs / k, np.nan))
        strengths[rgba.shape[:2]].append(k)

    if not groups:
        raise RuntimeError("No RGBA sources with visible watermark alpha were found.")
    size, stack = max(groups.items(), key=lambda kv: len(kv[1]))
    if len(stack) < min_images:
        raise RuntimeError(f"Only {len(stack)} usable sources at {size}; need {min_images}.")

    arr = np.stack(stack)
    with np.errstate(all="ignore"):
        med = np.nanmedian(arr, axis=0)
        spread = np.nanstd(arr, axis=0)
    holes = ~np.isfinite(med)
    med = np.nan_to_num(med, nan=0.0)

    # sources were normalised on the way in, so the median is already 0..1 coverage
    ks = np.array(strengths[size])
    k_main = float(np.median(ks))
    profile = np.clip(med, 0.0, 1.0)

    filled_by_period = 0
    h, w = profile.shape
    for shift in (H_PERIOD, -H_PERIOD, 2 * H_PERIOD, -2 * H_PERIOD):
        if not holes.any():
            break
        src = np.roll(profile, shift, axis=1)
        src_holes = np.roll(holes, shift, axis=1)
        inside = np.zeros_like(holes)
        if shift > 0:
            inside[:, shift:] = True
        else:
            inside[:, :w + shift] = True
        take = holes & inside & ~src_holes
        profile[take] = src[take]
        holes &= ~take
        filled_by_period += int(take.sum())

    inpainted = int(holes.sum())
    if inpainted:
        q = np.clip(profile * 255.0, 0, 255).astype(np.uint8)
        q = cv2.inpaint(q, holes.astype(np.uint8), 3, cv2.INPAINT_TELEA)
        profile = q.astype(np.float32) / 255.0

    strong = profile > 0.8
    stats = {
        "canvas": f"{size[1]}x{size[0]}",
        "sources": len(stack),
        "main_strength": round(k_main, 1),
        "strengths_seen": sorted({int(round(v)) for v in ks}),
        "ink_px": int((profile > 0.5).sum()),
        "ink_fraction": round(float((profile > 0.05).mean()), 4),
        "filled_from_period": filled_by_period,
        "inpainted_px": inpainted,
        "cross_source_std_on_ink": round(float(np.nanmean(spread[strong])), 3) if strong.any() else None,
    }
    return profile, stats


# ---------------------------------------------------------------------------
def learn_maik_tile(source: Path, limit: int = 60) -> tuple[np.ndarray, dict]:
    """Fold the finished catalogue images over the Maik Cat lattice."""
    acc = np.zeros(MAIK_TX * MAIK_TY, np.float64)
    cnt = np.zeros(MAIK_TX * MAIK_TY, np.float64)
    used = 0
    for d in sorted(source.iterdir()):
        if not d.is_dir() or used >= limit:
            continue
        for f in sorted(d.iterdir()):
            if not (f.is_file() and "maikcat" in f.name.lower()
                    and f.suffix.lower() in IMAGE_EXTS):
                continue
            rgba = read_rgba(f)
            if rgba is None:
                break
            a = rgba[:, :, 3:4].astype(np.float32) / 255.0
            bgr = (rgba[:, :, :3].astype(np.float32) * a + 255.0 * (1 - a)).astype(np.uint8)
            g = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
            dark = 255.0 - g
            bg = dark < 45                       # white background + the mark itself
            ys, xs = np.mgrid[0:g.shape[0], 0:g.shape[1]]
            j = np.floor_divide(ys, MAIK_TY)
            idx = (np.mod(ys, MAIK_TY) * MAIK_TX + np.mod(xs - j * MAIK_SX, MAIK_TX)).astype(np.int64)
            s = np.bincount(idx[bg], weights=dark[bg], minlength=MAIK_TX * MAIK_TY)
            c = np.bincount(idx[bg], minlength=MAIK_TX * MAIK_TY)
            probe = np.zeros_like(s)
            m = c > 0
            probe[m] = s[m] / c[m]
            if probe.max() < 20:                 # this image is not watermarked yet
                break
            acc += s
            cnt += c
            used += 1
            break
    if used < 5:
        raise RuntimeError(f"Only {used} Maik Cat reference images found under {source}.")
    tile = np.zeros(MAIK_TX * MAIK_TY)
    m = cnt > 0
    tile[m] = acc[m] / cnt[m]
    tile = tile.reshape(MAIK_TY, MAIK_TX)
    baseline = float(np.percentile(tile, 25))    # JPEG haze in the source backgrounds
    tile = np.clip(tile - baseline, 0.0, None)
    stats = {
        "sources": used,
        "lattice": f"{MAIK_TX}x{MAIK_TY} offset {MAIK_SX}",
        "baseline_removed": round(baseline, 2),
        "peak_darkening": round(float(tile.max()), 1),
        "coverage": round(float((cnt > 0).mean()), 4),
    }
    return tile, stats


# ---------------------------------------------------------------------------
def main() -> int:
    ap = argparse.ArgumentParser(description="Learn watermark assets from real images")
    ap.add_argument("--eco-source", help="Directory of original Eco Trade images (RGBA)")
    ap.add_argument("--maik-source", help="Directory of finished Maik Cat catalogue images")
    ap.add_argument("--out-dir", default=str(ASSET_DIR))
    args = ap.parse_args()

    if not args.eco_source and not args.maik_source:
        ap.error("Give --eco-source and/or --maik-source.")

    out = Path(args.out_dir)
    out.mkdir(parents=True, exist_ok=True)

    if args.eco_source:
        profile, stats = learn_eco_profile(Path(args.eco_source))
        target = out / ECO_PROFILE_ASSET.name
        cv2.imencode(".png", np.clip(np.round(profile * ECO_PROFILE_SCALE), 0, 255)
                     .astype(np.uint8))[1].tofile(str(target))
        print("Eco Trade profile ->", target)
        for k, v in stats.items():
            print(f"   {k:<26} {v}")

    if args.maik_source:
        tile, stats = learn_maik_tile(Path(args.maik_source))
        target = out / MAIK_TILE_ASSET.name
        cv2.imencode(".png", np.clip(np.round(tile * MAIK_TILE_SCALE), 0, 255)
                     .astype(np.uint8))[1].tofile(str(target))
        print("Maik Cat tile ->", target)
        for k, v in stats.items():
            print(f"   {k:<26} {v}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
