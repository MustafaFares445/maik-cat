# Safe item image repair command

Use the cross-code-safe wrapper for current audit reports:

```bash
php artisan media:repair-item-image-links-safe \
  storage/app/reports/item-image-link-audit/20260912-011644/image_link_audit.csv \
  --output=storage/app/reports/item-image-repair/20260912-011644-precheck
```

This is a dry run. Review `repair_plan.csv`, `unsolved_images.csv`, and `cross_code_review.csv`.

To apply only the safe existing-project repairs:

```bash
php artisan media:repair-item-image-links-safe \
  storage/app/reports/item-image-link-audit/20260912-011644/image_link_audit.csv \
  --output=storage/app/reports/item-image-repair/20260912-011644-applied \
  --apply
```

The command never downloads Ecotrade images. It excludes raw/direct Ecotrade media and suspicious cross-code reused image hashes from donors.
