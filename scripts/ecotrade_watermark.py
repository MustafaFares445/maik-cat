#!/usr/bin/env python3
r"""Shared watermark model for the Eco Trade -> Maik Cat pipeline.

What the Eco Trade watermark actually is
----------------------------------------
Every Eco Trade source image carries the *same* watermark artwork, composited as
plain white ink with a single opacity:

    out = orig * (1 - a) + 255 * a          a = k * profile(x, y) / 255

* ``profile`` is a fixed 0..1 coverage map anchored to the image canvas.  It does
  not move, rotate or rescale between images - only the canvas size changes.
* ``k`` is the ink strength.  Two values occur in the current corpus, 38 and 129
  (out of 255); the script measures it per image instead of assuming.

Because the model is exact and linear it can be inverted exactly, which is what
``unblend`` does - no inpainting, no guessing, no texture damage.

``k`` is not assumed, and not taken as a single number either.  ``measure_alpha_curve``
reads the ink's effective opacity *at each coverage level* off the image itself, from
how much brighter an inked pixel is than its un-inked neighbours.  A single global
strength fits the stroke interior and misses its outline - the stored profile's edges
are a median over many separately-resampled sources, so they are slightly softer than
any one image's - and that mismatch is what leaves a thin bright rim tracing the mark
after everything else has gone.  Per-level opacity absorbs it, because the outline and
the interior each get their own answer.

What no inverse can close is the last 1-2 px on the stroke contour, where the coverage
map's edge is a pixel or so out and the error is amplified by ``1 / (1 - a)``.
``unexplained_band`` finds that rim from the coverage geometry and hands it to
inpainting.  It is a small, deliberate exception to "no inpainting": a thin coherent
contour is the most visible artefact of the whole process, and it barely moves any
average - which is exactly how earlier versions passed it as clean.

Two things keep that inverse honest.  ``capped_coverage`` refuses to correct a pixel
by more than the pixel itself can justify: white ink of coverage ``a`` cannot leave a
pixel darker than ``255*a``, so where the source sits below that floor the stroke has
genuinely thinned there and the full correction would drive it past black, burning the
outline in as a dark ring.  And where the source was recompressed *after* watermarking,
some pixels no longer satisfy the equation at all - that residue is unrecoverable, and
is reported rather than painted over.  ``polish_residual`` and ``repair_unexplained``
can hide it, but they edit pixels the model cannot account for, so both are opt-in.

On RGBA sources the watermark is *visible in the alpha channel* wherever the
background is transparent, which gives a ground-truth signal for both alignment
and strength.  On flattened (JPEG / erased-background) sources it survives only
over the object, so the strength is recovered from the brightness lift instead.

The Maik Cat watermark is modelled the same way, as a 320x180 brick lattice
(rows offset by 160 px) of grey ink blended toward ``MAIK_INK``.
"""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np

ASSET_DIR = Path(__file__).resolve().parent / "assets"
ECO_PROFILE_ASSET = ASSET_DIR / "ecotrade-watermark-profile.png"
MAIK_TILE_ASSET = ASSET_DIR / "maikcat-watermark-tile.png"

# Maik Cat lattice, measured from the already-correct catalogue images.
MAIK_TX, MAIK_TY, MAIK_SX = 320, 180, 160
MAIK_INK = 213.0          # colour the mark blends toward
MAIK_GAIN = 0.856         # calibrates the stored tile to real darkening-on-white
MAIK_REF_WIDTH = 1200.0   # catalogue images are ~1200 px wide at 1.0 scale
MAIK_TILE_SCALE = 4.0     # stored tile is x4 for 8-bit precision

ECO_PROFILE_SCALE = 255.0  # stored profile is 0..255 for 0..1 coverage
KNOWN_STRENGTHS = (38.0, 129.0)

# The mark is a diagonal band that repeats horizontally on this period; confirmed by
# autocorrelating the learned profile (r = 0.41 at 176 px, nothing else close).  The
# stored profile is 2.1 periods wide, so it is folded into one period and re-tiled -
# that is what lets the coverage map cover a canvas of any width at any phase.
ECO_PERIOD = 176

# Coverage levels at which the ink's effective opacity is measured.  A single global
# strength is not enough: the profile's stroke edge is an average over many resampled
# sources, so it is slightly softer than any one image's, and a linear model
# under-corrects the outline while the interior is right - which is exactly the thin
# bright rim that survived earlier removals.  Measuring opacity per coverage level
# absorbs that, because the outline and the interior get their own answer.
ALPHA_LEVELS = np.array([0.0, 0.05, 0.12, 0.22, 0.34, 0.46, 0.58, 0.70, 0.82, 0.92, 1.0])
ALPHA_CEILING = 0.86      # highest opacity the inverse stays numerically sane at


# ---------------------------------------------------------------------------
# image helpers
# ---------------------------------------------------------------------------
def read_rgba(path: Path) -> np.ndarray | None:
    """Read any image as BGRA. Fully opaque alpha is synthesised when missing."""
    raw = cv2.imdecode(np.fromfile(str(path), dtype=np.uint8), cv2.IMREAD_UNCHANGED)
    if raw is None:
        return None
    if raw.ndim == 2:
        raw = cv2.cvtColor(raw, cv2.COLOR_GRAY2BGR)
    if raw.shape[2] == 3:
        raw = np.dstack([raw, np.full(raw.shape[:2], 255, np.uint8)])
    return raw[:, :, :4]


def write_image(path: Path, image: np.ndarray) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    ext = path.suffix.lower()
    if ext in {".jpg", ".jpeg"}:
        params = [cv2.IMWRITE_JPEG_QUALITY, 95]
    elif ext == ".png":
        params = [cv2.IMWRITE_PNG_COMPRESSION, 6]
    elif ext == ".webp":
        params = [cv2.IMWRITE_WEBP_QUALITY, 95]
    else:
        params = []
    ok, buf = cv2.imencode(ext if ext else ".png", image, params)
    if not ok:
        raise RuntimeError(f"Could not encode {path}")
    buf.tofile(str(path))


def flatten_white(rgba: np.ndarray) -> np.ndarray:
    a = rgba[:, :, 3:4].astype(np.float32) / 255.0
    rgb = rgba[:, :, :3].astype(np.float32)
    return np.clip(rgb * a + 255.0 * (1.0 - a), 0, 255).astype(np.uint8)


def place(base: np.ndarray, h: int, w: int, dx: int, dy: int) -> np.ndarray:
    """Anchor ``base`` into an (h, w) canvas at offset (dx, dy), zero elsewhere."""
    out = np.zeros((h, w), np.float32)
    bh, bw = base.shape[:2]
    y0, y1 = max(0, dy), min(h, bh + dy)
    x0, x1 = max(0, dx), min(w, bw + dx)
    if y1 > y0 and x1 > x0:
        out[y0:y1, x0:x1] = base[y0 - dy:y1 - dy, x0 - dx:x1 - dx]
    return out


# ---------------------------------------------------------------------------
# Eco Trade model
# ---------------------------------------------------------------------------
@dataclass
class EcoFit:
    detected: bool
    strength: float          # k, 0..255 - the opacity at full coverage
    dx: int
    dy: int
    evidence: str            # "alpha" | "trace" | "none"
    alpha_ink_px: int
    alpha_match: float       # correlation of observed vs predicted alpha (alpha evidence)
    lift_ratio: float        # observed / expected brightness lift on the object
    ink_px: int
    amp: float = 0.0         # grey levels of watermark-shaped signal present
    amp_sigma: float = 0.0   # the same, in standard errors - this is the detector
    curve: tuple = ()        # measured opacity at each ALPHA_LEVELS entry


def load_eco_profile(path: Path | None = None) -> np.ndarray:
    p = path or ECO_PROFILE_ASSET
    img = cv2.imdecode(np.fromfile(str(p), dtype=np.uint8), cv2.IMREAD_UNCHANGED)
    if img is None:
        raise FileNotFoundError(f"Eco Trade profile asset missing: {p}")
    if img.ndim == 3:
        img = img[:, :, 0]
    return img.astype(np.float32) / ECO_PROFILE_SCALE


def eco_tile(profile: np.ndarray, period: int = ECO_PERIOD) -> np.ndarray:
    """Fold the stored profile into one horizontal period of the lattice.

    Averaging the periods together also fills the holes each one carries: a pixel the
    sources never saw at one phase was usually visible at another.
    """
    h, w = profile.shape
    acc = np.zeros((h, period), np.float64)
    cnt = np.zeros((h, period), np.float64)
    for x0 in range(0, w, period):
        seg = profile[:, x0:x0 + period]
        acc[:, :seg.shape[1]] += seg
        cnt[:, :seg.shape[1]] += 1.0
    return (acc / np.maximum(cnt, 1.0)).astype(np.float32)


def eco_coverage(h: int, w: int, tile: np.ndarray, phase_x: int, off_y: int) -> np.ndarray:
    """Coverage map for a canvas, from a tile repeated across it at a given phase.

    ``phase_x`` is which tile column lands on x = 0, so it wraps at the period;
    ``off_y`` is where the band's top edge sits and may be negative or past the canvas.
    """
    th, tw = tile.shape
    out = np.zeros((h, w), np.float32)
    y0, y1 = max(0, off_y), min(h, off_y + th)
    if y1 <= y0:
        return out
    reps = int(np.ceil((w + tw) / tw)) + 1
    band = np.tile(tile[y0 - off_y:y1 - off_y], (1, reps))
    x0 = int(phase_x) % tw
    out[y0:y1] = band[:, x0:x0 + w]
    return out


def _xcorr(img: np.ndarray, tmpl: np.ndarray) -> tuple[np.ndarray, int, int]:
    """out[dy + oy, dx + ox] = sum_yx img(y, x) * tmpl(y - dy, x - dx)."""
    ih, iw = img.shape
    th, tw = tmpl.shape
    fh, fw = ih + th - 1, iw + tw - 1
    F = np.fft.rfft2(img, s=(fh, fw))
    G = np.fft.rfft2(tmpl[::-1, ::-1], s=(fh, fw))
    return np.fft.irfft2(F * G, s=(fh, fw)), th - 1, tw - 1


def align_candidates(
    signal: np.ndarray,
    weight: np.ndarray,
    tile: np.ndarray,
    top: int = 6,
    min_overlap: float = 0.30,
    search: int | None = None,
) -> list[tuple[float, int, int]]:
    """Rank (phase_x, off_y) placements of the lattice against a measured signal.

    Every phase inside the search window is scored at every vertical offset at once: the
    correlation against a single tile is computed by FFT, then summed at the period's
    stride, which is the same thing as correlating against the fully tiled band.

    ``search`` bounds the offset from the canvas origin, and should stay small.  Measured
    against the 37 sources whose alpha channel records the ink exactly, the mark sits at
    (0, 0) in every one of them - it is anchored to the canvas, not floating.  Searching
    freely finds better-correlating placements on a busy photograph than the true one,
    and a placement that is wrong in a way that still correlates produces a nonsense
    opacity curve and a removal that damages the image instead of cleaning it.

    Scores are cosine similarities, so they are comparable between placements; the
    caller re-scores the shortlist against the image to make the final choice.
    """
    h, w = signal.shape
    th, tw = tile.shape
    num_full, oy, ox = _xcorr(signal, tile)
    den_full, _, _ = _xcorr(weight.astype(np.float32), tile ** 2)
    energy = float((signal ** 2).sum())
    if energy <= 1e-9:
        return []
    lo_y = max(0, oy - th + 12)
    hi_y = min(num_full.shape[0], oy + h - 12)
    if search is not None:
        lo_y = max(lo_y, oy - search)
        hi_y = min(hi_y, oy + search + 1)
    ii = np.arange(lo_y, hi_y)
    if ii.size == 0:
        return []
    if search is None:
        phases = range(tw)
    else:
        phases = sorted({p % tw for p in range(-search, search + 1)})
    per_phase = []
    for phase in phases:
        dxs = np.concatenate([np.arange(phase, -tw - 1, -tw)[1:][::-1],
                              np.arange(phase, w, tw)])
        ji = dxs + ox
        ji = ji[(ji >= 0) & (ji < num_full.shape[1])]
        if ji.size == 0:
            continue
        per_phase.append((phase,
                          num_full[np.ix_(ii, ji)].sum(axis=1),
                          den_full[np.ix_(ii, ji)].sum(axis=1)))
    if not per_phase:
        return []
    dmax = max(float(d.max()) for _, _, d in per_phase)
    if dmax <= 0.0:
        return []
    ranked: list[tuple[float, int, int]] = []
    for phase, num, den in per_phase:
        ok = den >= min_overlap * dmax
        if not ok.any():
            continue
        score = np.where(ok, num / np.sqrt(np.maximum(den, 1e-9) * energy), -9.0)
        k = int(np.argmax(score))
        ranked.append((float(score[k]), phase, int(ii[k] - oy)))
    ranked.sort(reverse=True)
    # keep only placements that are genuinely distinct, not neighbours of one peak
    out: list[tuple[float, int, int]] = []
    for s, px, py in ranked:
        if any(min(abs(px - qx), tw - abs(px - qx)) < 10 and abs(py - qy) < 10
               for _, qx, qy in out):
            continue
        out.append((s, px, py))
        if len(out) >= top:
            break
    return out


def background_mask(bgr: np.ndarray) -> np.ndarray:
    """Flood-fill the near-white surround from the corners.

    The Maik Cat mark darkens the background by up to ~40 levels, so the fill
    tolerance has to be loose enough to flow straight through it.
    """
    h, w = bgr.shape[:2]
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY)
    seedable = (gray >= 190).astype(np.uint8) * 255
    ff = np.zeros((h + 2, w + 2), np.uint8)
    for seed in ((0, 0), (w - 1, 0), (0, h - 1), (w - 1, h - 1)):
        if seedable[seed[1], seed[0]]:
            cv2.floodFill(seedable, ff, seed, 128)
    return seedable == 128


def object_mask(rgba: np.ndarray) -> np.ndarray:
    """Pixels showing the product itself, never the surround.

    RGBA sources mark the cut-out background with alpha 0.  Flattened sources have no
    alpha at all, so the white surround has to be flood-filled away - without this the
    background counts as "object" and every measurement over it is meaningless.
    """
    a = rgba[:, :, 3]
    if int((a > 250).sum()) < a.size - 16:
        return a > 250
    return ~background_mask(flatten_white(rgba))


def alpha_evidence(rgba: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Watermark alpha observed over the transparent background, and its validity mask.

    Pixels near the cut-out edge are excluded: their alpha is object antialiasing,
    not watermark ink.  The object threshold sits above the strongest ink strength
    seen (129) so that a heavy watermark is never mistaken for the object itself.
    """
    a = rgba[:, :, 3].astype(np.float32)
    near_object = cv2.dilate((a > 200).astype(np.uint8), np.ones((7, 7), np.uint8)) > 0
    valid = ~near_object
    return np.where(valid, a, 0.0), valid


def lift_statistic(gray: np.ndarray, amap: np.ndarray, obj: np.ndarray) -> tuple[float, float, int]:
    """Compare predicted-ink pixels with their non-ink neighbourhood, over the object.

    Returns (observed lift, expected lift for ``amap``, sample count).  Their ratio is
    ~1 when ``amap`` really is the watermark and ~0 when it is not.
    """
    ink = (amap > 25) & obj
    non = (amap < 3) & obj
    if int(ink.sum()) < 40:
        return 0.0, 0.0, 0
    nonf = non.astype(np.float32)
    num = cv2.blur(gray * nonf, (15, 15))
    den = cv2.blur(nonf, (15, 15))
    est = np.where(den > 0.2, num / np.maximum(den, 1e-6), np.nan)
    ok = ink & np.isfinite(est)
    n = int(ok.sum())
    if n < 40:
        return 0.0, 0.0, 0
    base = est[ok]
    aw = amap[ok] / 255.0
    observed = float((gray[ok] - base).mean())
    # forward model: out = orig + a*(255 - orig), so the lift is a*(255 - orig).
    # `base` estimates orig from the surrounding un-inked pixels.
    expected = float((aw * (255.0 - base)).mean())
    return observed, expected, n


def _neighbourhood_mean(
    values: np.ndarray, mask: np.ndarray, window: int, floor: float = 0.05,
) -> np.ndarray:
    """Mean of ``values`` over ``mask`` pixels within a window, NaN where unsupported."""
    m = mask.astype(np.float32)
    den = cv2.blur(m, (window, window))
    num = cv2.blur(values * m, (window, window))
    return np.where(den > floor, num / np.maximum(den, 1e-6), np.nan)


def trace_amplitude(
    bgr: np.ndarray,
    coverage: np.ndarray,
    obj: np.ndarray,
    window: int = 41,
) -> tuple[float, float, int]:
    """Grey levels of watermark-shaped signal in the image, and its standard error.

    This is the number that says whether the mark is *visible*, and it is what both
    detection and verification are built on.  Local shading is removed with a
    neighbourhood mean, then what is left is regressed onto the coverage pattern.  The
    product's own texture is uncorrelated with the lattice, so it widens the error bar
    instead of moving the estimate - which is why this separates a marked image
    (80-plus levels, hundreds of sigma) from a clean one (a couple of levels, under 6
    sigma) with no overlap.

    It replaces the stroke-outline sharpness metric earlier versions trusted.  That one
    could be *reduced* by over-correcting until the outline crushed to black, so it
    scored a burnt-in dark ghost better than an untouched image, and passed results that
    still plainly showed the mark.
    """
    near = cv2.dilate((coverage > 0.02).astype(np.uint8), np.ones((21, 21), np.uint8)) > 0
    region = ((coverage > 0.02) | near) & obj
    if int(region.sum()) < 300:
        return 0.0, 0.0, 0
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
    gbar = _neighbourhood_mean(gray, region, window, floor=0.08)
    cbar = _neighbourhood_mean(coverage, region, window, floor=0.08)
    ok = region & np.isfinite(gbar) & np.isfinite(cbar)
    n = int(ok.sum())
    if n < 300:
        return 0.0, 0.0, 0
    y = (gray - gbar)[ok]
    x = (coverage - cbar)[ok]
    sxx = float((x * x).sum())
    if sxx < 1e-6:
        return 0.0, 0.0, n
    amp = float((x * y).sum() / sxx)
    resid = y - amp * x
    se = float(np.sqrt(float((resid ** 2).sum()) / max(n - 2, 1) / sxx))
    return amp, se, n


def trace_coherence(
    bgr: np.ndarray,
    coverage: np.ndarray,
    obj: np.ndarray,
    phase_x: int,
    period: int = ECO_PERIOD,
    min_px: int = 700,
) -> tuple[float, int]:
    """Weakest repeat's share of the whole mark's amplitude, across the lattice.

    Searching every placement means the best-scoring one is picked out of a very large
    field, so on a clean image the winner's amplitude is inflated simply by being the
    maximum of many noisy numbers - enough to clear a plain threshold and "remove" a
    mark that was never there.  A real mark does not just score well once: it repeats,
    and every repeat carries the same ink.  Requiring the weakest repeat to agree with
    the whole is what noise cannot fake, and it costs nothing on a genuine mark.

    Returns (weakest repeat / overall, repeats measured); 0.0 when there is only one.
    """
    h, w = coverage.shape
    whole = trace_amplitude(bgr, coverage, obj)[0]
    if whole <= 0.0:
        return 0.0, 0
    amps = []
    start = (int(phase_x) % period) - period
    for x0 in range(start, w, period):
        lo, hi = max(0, x0), min(w, x0 + period)
        if hi - lo < period // 3:
            continue
        slab = np.zeros_like(coverage)
        slab[:, lo:hi] = coverage[:, lo:hi]
        if int(((slab > 0.5) & obj).sum()) < min_px:
            continue
        amp, se, n = trace_amplitude(bgr, slab, obj)
        if n > 0 and se > 0.0:
            amps.append(amp)
    if len(amps) < 2:
        return 0.0, len(amps)
    return float(min(amps) / whole), len(amps)


def zone_residuals(
    bgr: np.ndarray,
    coverage: np.ndarray,
    obj: np.ndarray,
    window: int = 41,
) -> tuple[float, float]:
    """Signed grey-level residual in the stroke's interior and in its outline band.

    The overall amplitude can read zero while the mark is still perfectly visible, if
    the interior is left light and the outline has been pushed dark - the two cancel.
    Splitting them is what catches that, and it is the exact artefact a single scalar
    correction produces: it is right on average and wrong everywhere.
    """
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
    base = _neighbourhood_mean(gray, (coverage < 0.02) & obj, window)
    good = obj & np.isfinite(base)
    r = gray - np.where(np.isfinite(base), base, 0.0)

    def mean_of(mask: np.ndarray) -> float:
        return float(r[mask].mean()) if int(mask.sum()) >= 60 else 0.0

    return (mean_of(good & (coverage > 0.90)),
            mean_of(good & (coverage > 0.15) & (coverage < 0.85)))


def residual_score(bgr: np.ndarray, coverage: np.ndarray, obj: np.ndarray) -> float:
    """Worst of the three residual readings - what acceptance is actually judged on."""
    amp = trace_amplitude(bgr, coverage, obj)[0]
    interior, transition = zone_residuals(bgr, coverage, obj)
    return max(abs(amp), abs(interior), abs(transition))


def measure_alpha_curve(
    bgr: np.ndarray,
    coverage: np.ndarray,
    obj: np.ndarray,
    window: int = 41,
    min_headroom: float = 28.0,
    min_px: int = 90,
    signed: bool = False,
) -> tuple[np.ndarray, np.ndarray]:
    """Effective ink opacity at each coverage level, read off this image.

    White ink of opacity ``a`` lifts a pixel to ``orig + a * (255 - orig)``, so
    ``a = (observed - orig) / (255 - orig)`` with ``orig`` estimated from the un-inked
    pixels nearby.  Averaging that within coverage bands measures the ink directly
    instead of assuming one global strength scaled by the profile, and it is the step
    that finally clears the stroke outline.

    Pixels too close to white are skipped: white ink cannot show on them, so they carry
    no information about its opacity, only noise amplified by a small denominator.
    """
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
    base = _neighbourhood_mean(gray, (coverage < 0.02) & obj, window)
    head = 255.0 - base
    usable = obj & np.isfinite(base) & (head > min_headroom)
    with np.errstate(invalid="ignore"):
        observed = np.where(usable,
                            (gray - np.where(np.isfinite(base), base, 0.0))
                            / np.maximum(head, 1e-6), np.nan)
    curve = np.zeros_like(ALPHA_LEVELS)
    counts = np.zeros(ALPHA_LEVELS.size, dtype=int)
    for i, level in enumerate(ALPHA_LEVELS):
        if level <= 0.0:
            continue
        lo = 0.5 * (ALPHA_LEVELS[i - 1] + level)
        hi = 1.01 if i == ALPHA_LEVELS.size - 1 else 0.5 * (level + ALPHA_LEVELS[i + 1])
        band = usable & (coverage >= lo) & (coverage < hi)
        counts[i] = int(band.sum())
        if counts[i] >= min_px:
            curve[i] = float(np.median(observed[band]))
    solid = counts >= min_px
    if int(solid.sum()) < 2:
        return np.zeros_like(ALPHA_LEVELS), counts
    # thin bands borrow from the well-populated ones
    curve = np.interp(ALPHA_LEVELS, ALPHA_LEVELS[solid], curve[solid])
    if signed:
        # a correction pass has to be able to give ink back, not only take more out
        curve[0] = 0.0
        return np.clip(curve, -ALPHA_CEILING, ALPHA_CEILING), counts
    # for a first measurement the curve is forced to rise with coverage - more ink
    # cannot mean less opacity, and letting a noisy band invert that carves a ring
    curve = np.maximum.accumulate(np.clip(curve, 0.0, ALPHA_CEILING))
    curve[0] = 0.0
    return curve, counts


def alpha_curve_map(coverage: np.ndarray, curve: np.ndarray) -> np.ndarray:
    """Turn a measured opacity curve into an alpha map in 0..255."""
    return (np.interp(np.clip(coverage, 0.0, 1.0), ALPHA_LEVELS, curve) * 255.0).astype(np.float32)


def _object_signal(bgr: np.ndarray, obj: np.ndarray, window: int = 61) -> tuple[np.ndarray, np.ndarray]:
    """Per-pixel ink fraction implied by how much brighter a pixel is than its
    surroundings, plus the mask of pixels dark enough for that to mean anything."""
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
    base = _neighbourhood_mean(gray, obj, window, floor=0.15)
    head = 255.0 - base
    valid = obj & np.isfinite(base) & (head > 30.0)
    sig = np.where(valid, (gray - np.where(np.isfinite(base), base, 0.0))
                   / np.maximum(head, 1e-6), 0.0)
    sig = np.clip(sig, -0.9, 0.9).astype(np.float32)
    if valid.any():
        sig = np.where(valid, sig - float(sig[valid].mean()), 0.0).astype(np.float32)
    return sig, valid


def fit_eco(
    rgba: np.ndarray,
    profile: np.ndarray,
    search: int = 8,
    min_alpha_px: int = 250,
    min_amp: float = 12.0,
    min_amp_sigma: float = 25.0,
    min_coherence: float = 0.35,
    min_band_share: float = 0.55,
    tile: np.ndarray | None = None,
    bgr: np.ndarray | None = None,
) -> EcoFit:
    """Locate the Eco Trade lattice and measure its ink.

    Whether the mark is *there* is decided by ``trace_amplitude``: how many grey levels
    of lattice-shaped signal the image carries, and how many standard errors that is.
    A marked image measures 80-plus levels at 100-plus sigma and a clean one a couple of
    levels at under 6 sigma, so the thresholds sit in a wide empty gap - which matters,
    because a false positive damages an image that was fine.

    The lattice is generated by tiling one period across the canvas rather than stamping
    a fixed-width bitmap at the origin, so a canvas wider than the stored profile is
    covered end to end instead of only its left-hand 370 px.  ``min_band_share`` guards
    the other axis: a canvas the band cannot cover is still measured, but a hit there is
    returned as ``band_too_small`` for review rather than acted on.
    """
    h, w = rgba.shape[:2]
    if tile is None:
        tile = eco_tile(profile)
    if bgr is None:
        bgr = flatten_white(rgba)
    obs_alpha, valid = alpha_evidence(rgba)
    ink_band = valid & (obs_alpha > 8) & (obs_alpha < 250)
    alpha_px = int(ink_band.sum())
    obj = object_mask(rgba)
    none = EcoFit(False, 0.0, 0, 0, "none", alpha_px, 0.0, 0.0, 0)

    # Two places to look for the ink.  Where the background stayed transparent it is
    # recorded in the alpha channel and alignment is all but exact; on flattened or
    # erased-background sources it survives only over the product, so the placement has
    # to come from the brightness lift instead.
    candidates: list[tuple[float, int, int]] = []
    evidence = "trace"
    alpha_match = 0.0
    if alpha_px >= min_alpha_px:
        a = np.where(valid, obs_alpha, 0.0).astype(np.float32)
        a = np.where(valid, a - float(a[valid].mean()), 0.0).astype(np.float32)
        candidates = align_candidates(a, valid, tile, search=search)
        if candidates:
            evidence = "alpha"
    if not candidates:
        sig, sig_valid = _object_signal(bgr, obj)
        candidates = align_candidates(sig, sig_valid, tile, search=search)
    # the canvas origin is where the mark actually sits, so it is always tried
    if not any(px == 0 and py == 0 for _, px, py in candidates):
        candidates.append((0.0, 0, 0))
    if not candidates:
        return none

    # The band is one row of the lattice and the stored profile is one canvas high, so on
    # a much taller canvas it can only model a strip of it.  Every source whose ink is
    # recorded exactly is a single band filling its canvas, and there is no measured
    # example of how the mark repeats down a taller one.  Such a canvas is still measured -
    # a strip of it is better than nothing, and reporting thousands of images as unchecked
    # buries the few that matter - but a positive there is never acted on, because a strip
    # modelling a fraction of the image correlates with product structure often enough to
    # look like a mark, and "removing" one would damage an image that was fine.
    tall = float(tile.shape[0]) < min_band_share * float(h)

    # The shortlist is re-scored against the image itself, because the correlation that
    # produced it is blind to how much ink a placement actually explains.  A placement
    # whose band barely reaches the product is dropped: too few pixels to trust.
    best = None
    for _, phase_x, off_y in candidates:
        cov = eco_coverage(h, w, tile, phase_x, off_y)
        core = int(((cov > 0.5) & obj).sum())
        if core < 400 or core < 0.004 * float(obj.sum()):
            continue
        amp, se, n = trace_amplitude(bgr, cov, obj)
        if se <= 0.0 or n <= 0:
            continue
        sigma = amp / se
        if best is None or sigma > best[0]:
            best = (sigma, amp, se, n, phase_x, off_y, cov)
    if best is None:
        return none
    sigma, amp, se, n, phase_x, off_y, cov = best

    if amp < min_amp or sigma < min_amp_sigma:
        return EcoFit(False, 0.0, phase_x, off_y, "none", alpha_px, 0.0, 0.0, n,
                      round(amp, 2), round(sigma, 1))
    if evidence != "alpha":
        share, repeats = trace_coherence(bgr, cov, obj, phase_x)
        if repeats >= 2 and share < min_coherence:
            return EcoFit(False, 0.0, phase_x, off_y, "none", alpha_px, 0.0, 0.0, n,
                          round(amp, 2), round(sigma, 1))
    if tall:
        # Something lattice-shaped is here, but only a strip of this canvas is modelled,
        # so it cannot be confirmed - and acting on it risks damaging a clean image.
        # Reported for review rather than removed or quietly called clean.
        return EcoFit(False, 0.0, phase_x, off_y, "band_too_small", alpha_px, 0.0, 0.0, n,
                      round(amp, 2), round(sigma, 1))

    curve, _ = measure_alpha_curve(bgr, cov, obj)
    strength = float(curve[-1] * 255.0)
    if strength < 6.0:
        # Nothing measurable over the product - fall back to the opacity the alpha
        # channel recorded, which is the only other place the ink is written down.
        plateau = valid & (cov > 0.85) & (obs_alpha > 8)
        if int(plateau.sum()) >= 60:
            strength = float(np.median(obs_alpha[plateau]))
            curve = np.clip(ALPHA_LEVELS * strength / 255.0, 0.0, ALPHA_CEILING)
        else:
            return EcoFit(False, 0.0, phase_x, off_y, "none", alpha_px, 0.0, 0.0, n,
                          round(amp, 2), round(sigma, 1))
    if alpha_px >= min_alpha_px:
        m = valid & ((cov > 0.05) | ink_band)
        if int(m.sum()) >= 200 and cov[m].std() > 1e-6 and obs_alpha[m].std() > 1e-6:
            alpha_match = float(np.corrcoef(cov[m], obs_alpha[m])[0, 1])

    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
    obs, exp, ln = lift_statistic(gray, alpha_curve_map(cov, curve), obj)
    ratio = obs / exp if exp > 1.0 else 0.0
    return EcoFit(True, strength, phase_x, off_y, evidence, alpha_px,
                  round(alpha_match, 3), round(ratio, 3), int((cov > 0.05).sum()),
                  round(amp, 2), round(sigma, 1), tuple(round(float(v), 4) for v in curve))


def remove_eco(
    rgba: np.ndarray,
    coverage: np.ndarray,
    obj: np.ndarray,
    curve: np.ndarray,
    passes: int = 3,
    accept_amp: float = 2.0,
) -> tuple[np.ndarray, np.ndarray, float, float, int]:
    """Invert the ink, re-measuring what is left until nothing lattice-shaped remains.

    One pass is usually within a couple of grey levels; the extra passes exist because
    the opacity is measured against a base drawn from un-inked neighbours, and those
    neighbours are cleaner once the first pass has run.  Each pass is re-measured and
    the best result kept, so a pass that overshoots is discarded rather than compounded.

    Corrections stay *per coverage level*.  A single scalar gain on the whole curve is
    tempting - it drives the average residual to zero in a few steps - but it is right
    on average and wrong everywhere: it over-corrects the outline while the interior is
    already correct, and leaves a dark rim tracing the mark exactly as clearly as the
    light one it replaced.  Correcting each level against its own residual cannot do
    that, because each level is measured separately.

    Returns the cleaned RGBA, the alpha map used, the opacity curve it settled on, the
    residual before and after, and how many passes were an improvement.
    """
    bgr = flatten_white(rgba)
    amp0 = trace_amplitude(bgr, coverage, obj)[0]
    total = np.clip(np.array(curve, dtype=np.float64), 0.0, ALPHA_CEILING)

    def attempt(c: np.ndarray):
        amap = alpha_curve_map(coverage, c)
        cleaned = unblend(rgba, amap)
        flat = flatten_white(cleaned)
        return cleaned, amap, flat, residual_score(flat, coverage, obj)

    best_curve = total
    best, best_amap, best_flat, best_score = attempt(total)
    used = 1
    for _ in range(max(0, passes - 1)):
        if best_score <= accept_amp:
            break
        extra, _ = measure_alpha_curve(best_flat, coverage, obj, signed=True)
        if not np.any(np.abs(extra) > 1e-4):
            break
        # Opacities compose, they do not add: ink already taken out leaves less behind.
        # The step is damped because the residual a level is corrected against is itself
        # measured through the correction already applied, so a full step overshoots and
        # the next pass has to walk it back.
        improved = False
        for damping in (0.6, 1.0, 0.3):
            trial = np.clip(best_curve + damping * extra * (1.0 - best_curve),
                            0.0, ALPHA_CEILING)
            trial[0] = 0.0
            cleaned, amap, flat, score = attempt(trial)
            if score < best_score - 0.05:
                best_curve, best, best_amap = trial, cleaned, amap
                best_flat, best_score = flat, score
                used += 1
                improved = True
                break
        if not improved:
            break
    return (best, best_amap, best_curve, float(amp0),
            float(trace_amplitude(best_flat, coverage, obj)[0]), used)


def edge_energy(bgr: np.ndarray, coverage: np.ndarray, obj: np.ndarray) -> float:
    """How much sharper the watermark's stroke outline is than the object around it.

    1.0 means the outline has dissolved into the object; the untouched mark scores 2-8.
    Reported as a diagnostic only - do **not** decide anything on it, and never optimise
    against it.  Over-correcting drives the stroke interior to clipped black, which is
    flat, so this number keeps falling well past the point where the mark has turned
    into a visible dark ghost: its minimum is a badly damaged image.  Acceptance and
    strength both come from ``trace_amplitude`` instead, which measures the mark itself.
    """
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
    mag = cv2.magnitude(cv2.Sobel(gray, cv2.CV_32F, 1, 0, ksize=3),
                        cv2.Sobel(gray, cv2.CV_32F, 0, 1, ksize=3))
    band = cv2.morphologyEx((coverage > 0.25).astype(np.uint8), cv2.MORPH_GRADIENT,
                            np.ones((3, 3), np.uint8)) > 0
    band &= obj
    control = (coverage < 0.02) & obj
    if int(band.sum()) < 50 or int(control.sum()) < 200:
        return float("nan")
    ref = float(np.median(mag[control]))
    return float(np.median(mag[band]) / ref) if ref > 1e-6 else float("nan")


def unblend(rgba: np.ndarray, amap: np.ndarray) -> np.ndarray:
    """Invert `white ink at coverage amap/255` composited over an RGBA source.

    Straight-alpha compositing gives
        a_out = aw + a_src * (1 - aw)
        c_out * a_out = 255 * aw + c_src * a_src * (1 - aw)
    which inverts exactly.  Fully opaque pixels reduce to c_src = (c_out - 255*aw)/(1-aw),
    and background pixels fall back to transparent white.
    """
    aw = np.clip(amap / 255.0, 0.0, 0.85)[:, :, None]
    a_out = rgba[:, :, 3:4].astype(np.float32) / 255.0
    c_out = rgba[:, :, :3].astype(np.float32)
    a_src = np.clip((a_out - aw) / np.maximum(1.0 - aw, 1e-6), 0.0, 1.0)
    denom = np.maximum(a_src * (1.0 - aw), 1e-6)
    c_src = np.where(a_src > 0.004, (c_out * a_out - 255.0 * aw) / denom, 255.0)
    out = np.empty_like(rgba)
    out[:, :, :3] = np.clip(c_src, 0, 255).astype(np.uint8)
    out[:, :, 3] = np.clip(a_src[:, :, 0] * 255.0, 0, 255).astype(np.uint8)
    return out


def capped_coverage(
    rgba: np.ndarray,
    coverage: np.ndarray,
    strength: float,
    obj: np.ndarray,
) -> tuple[np.ndarray, int]:
    """Coverage map with the model overruled wherever the pixel contradicts it.

    A pixel carrying coverage ``a`` of white ink cannot be darker than ``255*a`` - that
    is the floor the compositing equation imposes.  Where the observed pixel sits below
    that floor, the ink there is genuinely weaker than the shared profile says (the
    stroke thins or drops out under the source's own resampling), and applying the full
    inverse would drive the pixel past black and burn the stroke's outline in as a hard
    dark ring.

    At those pixels the coverage is re-measured from the image itself - how much
    brighter it is than its un-inked surroundings - and the smaller of the two is used.
    Everywhere else the model is left alone.  Nothing is painted over or blurred: the
    correction is simply the right size at every pixel.

    Returns the alpha map in 0..255 and the number of pixels that were overruled.
    """
    bgr = flatten_white(rgba)
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
    a_model = np.clip(coverage * strength / 255.0, 0.0, 0.85)

    non = ((coverage < 0.02) & obj).astype(np.float32)
    den = cv2.blur(non, (31, 31))
    base = np.where(den > 0.06,
                    cv2.blur(gray * non, (31, 31)) / np.maximum(den, 1e-6), np.nan)
    floor = bgr.astype(np.float32).min(axis=2) / 255.0
    with np.errstate(invalid="ignore"):
        a_data = np.clip((gray - base) / np.maximum(255.0 - base, 1e-6), 0.0, 0.85)

    overruled = (a_model > floor * 0.97) & np.isfinite(a_data)
    a = np.where(overruled, np.minimum(a_model, a_data), a_model).astype(np.float32)
    # a touch of smoothing so a capped pixel does not sit next to an uncapped one
    a = np.minimum(cv2.GaussianBlur(a, (0, 0), 0.6), a_model)
    return a * 255.0, int(overruled.sum())


def out_of_gamut(rgba: np.ndarray, amap: np.ndarray) -> np.ndarray:
    """Pixels the inverse cannot explain, where the recovered colour leaves 0..255.

    A pixel carrying ink of coverage ``a`` can never be darker than ``255*a``; if it is,
    the ink there was weaker than the model says and the inverse would clip to black.
    Those pixels are a model failure, not data - clipping them leaves the stroke's
    outline burned into the result, which is exactly the artefact this catches.
    """
    aw = np.clip(amap / 255.0, 0.0, 0.85)
    floor = rgba[:, :, :3].astype(np.float32).min(axis=2) / 255.0
    return (aw > floor * 0.995) & (amap > 8)


def _extend_object(bgr: np.ndarray, obj: np.ndarray, k: int = 11) -> np.ndarray:
    """Replace the surround with a smooth continuation of the product's own colour."""
    objf = obj.astype(np.float32)
    den = cv2.blur(objf, (k, k))
    out = bgr.astype(np.float32).copy()
    for c in range(3):
        num = cv2.blur(bgr[:, :, c].astype(np.float32) * objf, (k, k))
        ext = num / np.maximum(den, 1e-6)
        out[:, :, c] = np.where(obj | (den <= 1e-6), out[:, :, c], ext)
    return np.clip(out, 0, 255).astype(np.uint8)


def repair_unexplained(
    bgr: np.ndarray,
    bad: np.ndarray,
    radius: int = 3,
    obj: np.ndarray | None = None,
) -> np.ndarray:
    """Fill model-failure pixels from their surroundings.

    With ``obj`` given, the surround is first replaced by a continuation of the product's
    own colour, so filling a pixel near the silhouette cannot drag the white background
    inwards - which shows up as pale smears eating into the product's edge.
    """
    if not bad.any():
        return bgr
    mask = cv2.dilate(bad.astype(np.uint8), np.ones((3, 3), np.uint8))
    source = bgr if obj is None else _extend_object(bgr, obj)
    filled = cv2.inpaint(source, mask, radius, cv2.INPAINT_TELEA)
    keep = mask > 0
    if obj is not None:
        keep &= obj
    out = bgr.copy()
    out[keep] = filled[keep]
    return out


def unexplained_band(
    cleaned: np.ndarray,
    coverage: np.ndarray,
    obj: np.ndarray,
    tol: float = 9.0,
    min_gradient: float = 0.30,
) -> np.ndarray:
    """Pixels along the stroke contour whose recovered value cannot be right.

    The coverage map's stroke edges are an average over many sources, so at a steep edge
    it can be a pixel or two out.  There the inverse divides by the wrong opacity, and
    because the error is amplified by ``1 / (1 - a)`` it reaches tens of grey levels on a
    1-2 px rim.  That rim is the most visible artefact of the whole process even though it
    barely moves any average - a thin coherent contour is exactly what the eye picks out,
    which is why residual *means* looked acceptable while the mark was still traceable.

    It is also genuinely unrecoverable: closing it needs this image's own coverage to
    within a percent, which a shared profile does not carry.  So the rim is found rather
    than fought - a steep coverage gradient, confirmed by the pixel still standing out
    from its surroundings - and handed to inpainting.  Only pixels that fail both tests
    qualify, and both are anchored to the watermark's own geometry, so product detail away
    from the stroke contour is never touched.
    """
    gx = cv2.Sobel(coverage, cv2.CV_32F, 1, 0, ksize=3)
    gy = cv2.Sobel(coverage, cv2.CV_32F, 0, 1, ksize=3)
    steep = cv2.magnitude(gx, gy) > min_gradient
    inside = cv2.erode(obj.astype(np.uint8), np.ones((5, 5), np.uint8)) > 0
    gray = cv2.cvtColor(cleaned, cv2.COLOR_BGR2GRAY).astype(np.float32)
    ref = (coverage < 0.02) & obj
    base = _neighbourhood_mean(gray, ref, 25)
    wide = _neighbourhood_mean(gray, ref, 61)
    base = np.where(np.isfinite(base), base, wide)
    off = np.abs(gray - np.where(np.isfinite(base), base, gray))
    return steep & inside & (coverage > 0.02) & (off > tol)


def surviving_trace(
    bgr: np.ndarray,
    coverage: np.ndarray,
    obj: np.ndarray,
    threshold: float = 4.0,
) -> np.ndarray:
    """Stroke pixels that still differ from their surroundings after the inverse.

    Everything upstream is a global correction: one strength for the whole image.
    Where the source was recompressed the error varies pixel to pixel, so a thin trace
    of the mark can survive a correction that is right on average.  This finds those
    pixels specifically - smoothed so single noisy pixels don't qualify - and they are
    the only ones worth painting over.
    """
    ink = (coverage > 0.10) & obj
    non = ((coverage < 0.02) & obj).astype(np.float32)
    if int(ink.sum()) < 40 or float(non.sum()) < 200:
        return np.zeros_like(ink)
    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
    den = cv2.blur(non, (25, 25))
    base = np.where(den > 0.08, cv2.blur(gray * non, (25, 25)) / np.maximum(den, 1e-6), np.nan)
    residual = np.where(np.isfinite(base) & ink, gray - base, 0.0)
    inkf = ink.astype(np.float32)
    smooth = cv2.blur(residual * inkf, (7, 7)) / np.maximum(cv2.blur(inkf, (7, 7)), 1e-6)
    return (np.abs(smooth) > threshold) & ink


def polish_residual(
    bgr: np.ndarray,
    coverage: np.ndarray,
    obj: np.ndarray,
    rounds: int = 2,
    window: int = 11,
) -> np.ndarray:
    """Flatten whatever offset survives inside the stroke area after un-blending.

    The algebraic inverse assumes the ink was composited onto the pixels we can see.
    When the source was recompressed *after* watermarking, ringing and block artefacts
    leave a faint offset the inverse cannot predict, so a trace of the stroke remains.

    This measures that leftover offset directly - stroke pixels against their un-inked
    neighbours - keeps only its low-frequency part, and subtracts it inside a feathered
    stroke mask.  Because only a smoothed offset is removed, the product's own texture
    and edges pass through untouched.
    """
    ink = (coverage > 0.12) & obj
    non = (coverage < 0.02) & obj
    if int(ink.sum()) < 40 or int(non.sum()) < 200:
        return bgr

    feather = cv2.GaussianBlur(np.clip(coverage / max(coverage.max(), 1e-6), 0, 1), (0, 0), 1.0)
    feather = np.where(obj, feather, 0.0).astype(np.float32)
    nonf = non.astype(np.float32)
    inkf = ink.astype(np.float32)
    den_non = cv2.blur(nonf, (window * 2 + 1, window * 2 + 1))
    den_ink = cv2.blur(inkf, (window, window))

    out = bgr.astype(np.float32)
    for _ in range(max(1, rounds)):
        gray = cv2.cvtColor(np.clip(out, 0, 255).astype(np.uint8), cv2.COLOR_BGR2GRAY)
        g = gray.astype(np.float32)
        base = np.where(den_non > 0.15,
                        cv2.blur(g * nonf, (window * 2 + 1, window * 2 + 1))
                        / np.maximum(den_non, 1e-6), np.nan)
        residual = np.where(np.isfinite(base) & ink, g - base, 0.0)
        # keep only the smooth part of the residual: that is the ink offset, not texture
        smooth = cv2.blur(residual * inkf, (window, window)) / np.maximum(den_ink, 1e-6)
        smooth = np.where(den_ink > 0.05, smooth, 0.0)
        correction = (smooth * feather)[:, :, None]
        out = out - correction
    return np.clip(out, 0, 255).astype(np.uint8)


def soften_edges(rgba: np.ndarray, amap: np.ndarray, strength_threshold: float = 60.0) -> np.ndarray:
    """Damp compression ringing left behind at high-opacity stroke boundaries.

    Only applied where the removed ink was strong; low-opacity marks invert cleanly
    and are left untouched.
    """
    if float(amap.max()) < strength_threshold:
        return rgba
    edge = cv2.morphologyEx((amap > 20).astype(np.uint8), cv2.MORPH_GRADIENT,
                            np.ones((3, 3), np.uint8)) > 0
    edge &= rgba[:, :, 3] > 250
    if not edge.any():
        return rgba
    out = rgba.copy()
    smooth = cv2.medianBlur(rgba[:, :, :3], 3)
    out[:, :, :3][edge] = smooth[edge]
    return out


# ---------------------------------------------------------------------------
# Maik Cat model
# ---------------------------------------------------------------------------
def load_maik_tile(path: Path | None = None) -> np.ndarray:
    p = path or MAIK_TILE_ASSET
    img = cv2.imdecode(np.fromfile(str(p), dtype=np.uint8), cv2.IMREAD_UNCHANGED)
    if img is None:
        raise FileNotFoundError(f"Maik Cat tile asset missing: {p}")
    if img.ndim == 3:
        img = img[:, :, 0]
    return img.astype(np.float32) / MAIK_TILE_SCALE * MAIK_GAIN


def maik_darkening(h: int, w: int, tile: np.ndarray, scale: float) -> np.ndarray:
    tw = max(16, int(round(MAIK_TX * scale)))
    th = max(12, int(round(MAIK_TY * scale)))
    sx = int(round(MAIK_SX * scale))
    t = cv2.resize(tile, (tw, th),
                   interpolation=cv2.INTER_AREA if scale < 1.0 else cv2.INTER_CUBIC)
    ys, xs = np.mgrid[0:h, 0:w]
    j = np.floor_divide(ys, th)
    return t[np.mod(ys, th), np.mod(xs - j * sx, tw)]


def maik_scale_for(width: int, mode: str = "auto") -> float:
    if mode == "absolute":
        return 1.0
    return float(np.clip(width / MAIK_REF_WIDTH, 0.18, 3.0))


def apply_maik(bgr: np.ndarray, tile: np.ndarray, scale: float) -> np.ndarray:
    h, w = bgr.shape[:2]
    d = maik_darkening(h, w, tile, scale)
    a = np.clip(d / (255.0 - MAIK_INK), 0.0, 1.0)[:, :, None]
    f = bgr.astype(np.float32)
    return np.clip(f + a * (MAIK_INK - f), 0, 255).astype(np.uint8)


def remove_maik(bgr: np.ndarray, tile: np.ndarray, scale: float) -> np.ndarray:
    """Undo ``apply_maik``, so a stamped image can still be measured accurately.

    Images stamped by an earlier run carry the Maik Cat lattice on top of an Eco Trade
    mark that was never removed.  That lattice biases the un-inked neighbours the Eco
    Trade opacity is measured against, so it is taken off a working copy first.  The
    copy is only ever measured, never saved - the image the caller keeps holds its
    original stamp.
    """
    h, w = bgr.shape[:2]
    d = maik_darkening(h, w, tile, scale)
    a = np.clip(d / (255.0 - MAIK_INK), 0.0, 0.9)[:, :, None]
    f = bgr.astype(np.float32)
    return np.clip((f - a * MAIK_INK) / np.maximum(1.0 - a, 1e-6), 0, 255).astype(np.uint8)


def maik_present(bgr: np.ndarray, tile: np.ndarray, scale: float) -> float:
    """Confidence that a Maik Cat mark is *already* on this image (double-stamp guard).

    Scored on the white surround, where the expected darkening is exactly the tile
    value.  Earlier batches stamped at their own scale, so the whole plausible range is
    swept; the answer is the winning scale's lead over the median scale rather than its
    raw score, because a real mark produces one sharp peak while background texture
    produces a diffuse response that would otherwise read as a match.

    ~0.7-1.2 means the mark is there, below ~0.4 means it is not.  Returns 0.0 when the
    surround is too small to judge - guessing there risks stamping twice.
    """
    h, w = bgr.shape[:2]
    bg = background_mask(bgr)
    if int(bg.sum()) < max(400, int(0.10 * h * w)):
        return 0.0

    def probe(g: np.ndarray, mask: np.ndarray, s: float) -> float:
        # `s` is never rounded on its own: a rounded scale can shift the tile period by
        # a pixel, which walks the pattern out of alignment across the canvas.
        d = maik_darkening(g.shape[0], g.shape[1], tile, s)
        ink = (d > 18) & mask
        non = (d < 2) & mask
        if int(ink.sum()) < 60 or int(non.sum()) < 200:
            return float("nan")
        expected = float(d[ink].mean())
        if expected <= 1.0:
            return float("nan")
        return float(g[non].mean() - g[ink].mean()) / expected

    gray = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
    sweep = sorted({scale, 1.0} | {round(v, 3) for v in np.arange(0.70, 1.71, 0.10)})

    # Find the scale cheaply on a downscaled copy, then score at full resolution where
    # the mark's contrast is intact.
    f = min(1.0, 640.0 / max(h, w))
    if f < 1.0:
        small = cv2.resize(bgr, (max(2, int(w * f)), max(2, int(h * f))),
                           interpolation=cv2.INTER_AREA)
        small_bg = background_mask(small)
        small_gray = cv2.cvtColor(small, cv2.COLOR_BGR2GRAY).astype(np.float32)
        coarse = {s: probe(small_gray, small_bg, s * f) for s in sweep}
        ranked = sorted((s for s, v in coarse.items() if np.isfinite(v)),
                        key=lambda s: -coarse[s])
        if len(ranked) < 3:
            return 0.0
        values = [v for v in coarse.values() if np.isfinite(v)]
        baseline = float(np.median(values))
        peak = max(probe(gray, bg, s) for s in ranked[:2])
        # rescale the coarse baseline to full-resolution contrast
        if np.isfinite(peak) and coarse[ranked[0]] > 1e-6:
            baseline *= peak / coarse[ranked[0]]
    else:
        values = [v for v in (probe(gray, bg, s) for s in sweep) if np.isfinite(v)]
        if len(values) < 3:
            return 0.0
        peak, baseline = max(values), float(np.median(values))
    return float(peak - baseline) if np.isfinite(peak) else 0.0
