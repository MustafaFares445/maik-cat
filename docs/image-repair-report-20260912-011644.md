# Image Repair Report — 2026-09-12 01:16:44

Source: `image_link_audit.csv` from report `20260912-011644`.

## Audit summary

- Items scanned: 6,952
- provenance_match: 5,915
- missing_media_file: 508
- provenance_match_visual_review: 168
- ambiguous_reference: 46
- group_mapping_conflict: 233
- confirmed_wrong_source: 77
- visual_match_missing_provenance: 3
- missing_reference: 2
- cross-code image hashes: 6 (13 affected item rows)

## Existing-project repair analysis

Using only report-visible `provenance_match` media as candidate donors, excluding cross-code-reused hashes and obvious `ecotrade-direct` filenames:

- 502 / 508 missing media files have a potential existing-project donor.
- 8 / 77 confirmed wrong sources have a potential existing-project donor.
- 510 total rows are potential repairs from existing project media.
- The server command still performs the authoritative live Media Library safety check and rejects `import_method=direct` / `ecotrade-direct` media before applying a repair.

## Remaining review set

535 item rows remain unresolved/manual review at report-analysis time:

- 13 cross-code image reuse rows
- 75 rows with no trusted existing project image
- 166 additional visual review rows (2 visual-review rows are already counted in cross-code reuse)
- 46 ambiguous expected-product rows
- 233 group-mapping conflicts
- 2 rows with no JSON reference

## Safety rule

Do not download or use raw Ecotrade source images for automatic repair. Automatic repair is allowed only from an already-existing local project image whose authoritative source hash/product identity matches the target, and which is not raw/direct Ecotrade media or a suspicious cross-code-reused image.
