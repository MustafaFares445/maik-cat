# Maik Cat Legal Pages

This project includes public Arabic and English legal pages suitable for linking from App Store Connect, Google Play Console, the mobile application, and the public website.

## Public URLs

Assuming `APP_URL=https://example.com`:

| Document | English | Arabic |
|---|---|---|
| Privacy Policy | `https://example.com/privacy-policy/en` | `https://example.com/privacy-policy/ar` |
| Terms of Use | `https://example.com/terms-of-use/en` | `https://example.com/terms-of-use/ar` |

The short URLs redirect to English:

- `/privacy-policy`
- `/terms-of-use`

## Required production configuration

Set accurate operator and contact details before submitting the application to a store:

```dotenv
LEGAL_BRAND_NAME="Maik Cat"
LEGAL_OPERATOR_NAME="Your registered company or operator name"
LEGAL_SUPPORT_EMAIL="support@example.com"
LEGAL_SUPPORT_PHONE=""
LEGAL_WEBSITE_URL="https://example.com"
LEGAL_EFFECTIVE_DATE="2026-08-02"
LEGAL_JURISDICTION="Country / State"
```

`LEGAL_SUPPORT_PHONE` and `LEGAL_JURISDICTION` may be left empty when they are not intended to appear on the public pages. Do not publish placeholder company, email, website, or jurisdiction values.

After changing environment values in production, refresh Laravel's configuration cache:

```bash
php artisan config:clear
php artisan config:cache
```

## Content scope

The legal content is tailored to the current Maik Cat codebase and covers:

- User login, profile data, and password reset.
- Saved catalytic converter items.
- Push-notification device tokens and notification history.
- Catalog search, serial codes, images, car groups, and alternate codes.
- Item weight and Pt, Pd, and Rh assay data.
- Multiple analysis records belonging to the same serial family.
- Metal market prices, charts, and value estimates.
- Calculator inputs, rates, units, and humidity adjustments.
- Authorized Excel imports, duplicate reviews, validation issues, and catalog image processing.
- Clear disclaimers that estimates are informational and are not binding purchase or sale offers.

## Validation

Run:

```bash
php artisan test tests/Feature/LegalPagesTest.php
```

Before store submission, open all four public URLs on desktop and mobile and confirm that the configured operator and contact details are correct.
