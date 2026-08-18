#!/usr/bin/env python3
r"""Restore pristine Eco Trade masters from a storage backup archive.

The v4/v6 runs overwrote every ``*-ecotrade-*`` master in place: they flattened the
transparent background, stamped Maik Cat and left the Eco Trade mark behind. Those
files cannot be repaired by re-running the pipeline - a fresh source is needed.

``storage/app/public.zip`` holds the pre-run copy of ``storage/app/public``, so this
pulls the original masters back out of it. Media-library conversions are left alone
(regenerate them afterwards with the app's own media command).

Usage:
    py scripts/ecotrade_restore_originals.py --zip storage/app/public.zip --dest <dir> --dry-run
    py scripts/ecotrade_restore_originals.py --zip storage/app/public.zip --dest <dir> --only 7047 7046
"""
from __future__ import annotations

import argparse
import shutil
import zipfile
from pathlib import Path

IMAGE_EXTS = {".png", ".jpg", ".jpeg", ".webp"}


def main() -> int:
    ap = argparse.ArgumentParser(description="Restore original Eco Trade masters from a backup zip")
    ap.add_argument("--zip", required=True, help="Backup archive of storage/app/public")
    ap.add_argument("--dest", required=True, help="Directory to restore into")
    ap.add_argument("--match", default="ecotrade", help="Substring a filename must contain")
    ap.add_argument("--only", nargs="*", help="Restore only these media-id folders")
    ap.add_argument("--include-conversions", action="store_true")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    src = Path(args.zip)
    if not src.exists():
        print(f"ERROR: archive not found: {src}")
        return 2
    dest = Path(args.dest)
    only = set(args.only or [])

    with zipfile.ZipFile(src) as z:
        names = [
            n for n in z.namelist()
            if not n.endswith("/")
            and args.match.lower() in n.lower()
            and Path(n).suffix.lower() in IMAGE_EXTS
            and (args.include_conversions or "/conversions/" not in n)
        ]
        picked = []
        for n in names:
            parts = Path(n).parts
            folder = parts[1] if len(parts) > 2 else parts[0]
            if only and folder not in only:
                continue
            picked.append((n, folder, parts[-1]))

        print(f"archive:  {src}")
        print(f"matched:  {len(picked)} file(s)")
        if args.dry_run:
            for n, folder, name in picked[:40]:
                print(f"   {folder}/{name}   ({z.getinfo(n).file_size:,} bytes)")
            if len(picked) > 40:
                print(f"   ... and {len(picked) - 40} more")
            print("dry run - nothing written")
            return 0

        written = 0
        for n, folder, name in picked:
            out = dest / folder / name
            out.parent.mkdir(parents=True, exist_ok=True)
            with z.open(n) as s, open(out, "wb") as t:
                shutil.copyfileobj(s, t)
            written += 1
        print(f"restored: {written} file(s) -> {dest}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
