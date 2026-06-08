# Laravel API Migration Plan

Goal: rebuild the existing PHP/API behavior in a clean Laravel project while keeping every frontend-facing API response compatible with the current contract. The frontend should not need route, payload, key-name, pagination, or response-wrapper changes.

Source of truth for this plan:

- Current route surface from `php artisan route:list --path=api` in this repo.
- Current response examples in `API_contract.md`, `CALCULATOR_PAGE_API_CONTRACT.md`, `KITCO_METALS_API_CONTRACT.md`, and `EXCEL_IMPORT_LOGIC.md`.
- API standards from `C:\Users\Mustafa_M_Fares\Downloads\api-standards.mdc`.
- Current Laravel conventions in this repo: Sanctum auth, Spatie Data, Form Requests, API Resources, Services, Pest tests, Filament admin, and `TransformApiCaseMiddleware`.

## Non-Negotiable Compatibility Rules

- Keep the same base path: `/api`.
- Keep all existing endpoint paths and methods.
- Keep response keys camelCase for clients. Internal Laravel code may use snake_case, but API output must match existing frontend contracts.
- Keep the current response wrappers exactly: examples include `{ "stats": ... }`, `{ "topItems": ... }`, `{ "estimate": ... }`, `{ "data": ... }`, `{ "message": ... }`.
- Keep existing auth requirements. Public endpoints stay public; profile, saved items, imports, duplicate resolution, refresh, logout, and user notifications stay behind Sanctum.
- Do not introduce frontend-only metadata or rename existing fields.
- Add contract tests for representative success and validation/error responses before cutover.

## Target Laravel Structure

Use standard Laravel app structure for this project unless a new business module is explicitly introduced later.

- Routes: `routes/api.php`.
- Controllers: `App\Http\Controllers\API\...` for mobile/API endpoints, plus `ImportController` for import workflow unless moved into API namespace during cleanup.
- Requests: `App\Http\Requests\API\...`.
- Resources: `App\Http\Resources\API\...`.
- Services: `App\Services\...` and `App\Services\Mobile\...`.
- DTOs: `App\Data\...` using Spatie Laravel Data where create/update workflows benefit from typed payloads.
- Filters: request classes plus query methods/traits for searchable endpoints.
- Tests: Pest feature tests for every API section and unit tests for calculators/import/metals services.
- Laravel Boost: verify Boost is installed in the new project before keeping `@php artisan boost:update --ansi` in Composer scripts. Run Boost update after framework/package updates and keep generated guidance aligned with this project.

## Section Estimates

| Section | Scope | Endpoints | Estimate |
| --- | --- | ---: | ---: |
| 1. Project foundation and contract lock | New Laravel app, dependencies, env, Sanctum, middleware, database baseline, response contract snapshots | 0 | 1.5-2.5 days |
| 2. Auth, profile, and app version | Login/logout/reset/profile/version check | 7 | 1.5-2 days |
| 3. Catalog and home APIs | Home stats/top items, car groups, item list/detail/similar, media URLs, saved flags | 6 | 2.5-3.5 days |
| 4. Metals, market, and calculator APIs | Metal spot feed/cache, charts, market notifications, estimate calculator | 6 | 3-4.5 days |
| 5. Saved items and notification inbox | Saved item CRUD-like workflow, inbox list/read states, test FCM | 7 | 2-3 days |
| 6. Import and duplicate workflow | Excel upload, batch status, issue/duplicate pagination, duplicate resolution | 5 | 3-5 days |
| 7. Admin/back-office parity | Filament users/items/groups/notifications/import visibility and permissions | 0 API | 2-3.5 days |
| 8. Test hardening and cutover | Contract tests, seeders, Postman parity, staging deploy, smoke testing | 31 total | 2.5-4 days |

Estimated total: 18-27 working days, depending on the quality of the pure PHP source data model and whether import edge cases must be fully revalidated from production workbooks.

## 1. Foundation And Contract Lock

Implementation tasks:

- Create a fresh Laravel project on the selected Laravel version, with PHP 8.3+.
- Install required packages: Sanctum, Spatie Data, Spatie Media Library, Spatie Permission, Spatie Query Builder, Laravel Excel, Firebase/Kreait, Filament, Pest.
- Port `TransformApiCaseMiddleware` behavior so requests can arrive as camelCase and responses leave as camelCase.
- Define database migrations for users, tokens, car groups, items, extra codes, metal prices, price calculations, saved items, import batches, duplicate reviews, import row issues, app versions, notifications, notification audiences, and admin campaigns.
- Create response snapshot tests from the current contract examples before rewriting deeper logic.
- Import current Postman collection as QA reference, not as implementation source.

Estimate: 1.5-2.5 days.

## 2. Auth, Profile, And App Version

| Method | Path | Auth | Controller | Response compatibility |
| --- | --- | --- | --- | --- |
| POST | `/api/auth/login` | Public | `AuthController@login` | Keep `token` and `user` object. Accept optional `fcmToken`. |
| POST | `/api/auth/forgot-password` | Public | `AuthController@forgotPassword` | Keep current message responses and validation behavior. |
| POST | `/api/auth/logout` | Sanctum | `AuthController@logout` | Keep `message: Logged out successfully.` |
| GET | `/api/profile` | Sanctum | `ProfileController@show` | Keep `{ data: { id, name, email } }`. |
| PATCH | `/api/profile` | Sanctum | `ProfileController@update` | Keep `{ message, data }`. |
| GET | `/api/app-version` | Public | `AppVersionController@check` | Keep same version payload as v1 alias. |
| POST | `/api/v1/app/version-check` | Public | `AppVersionController@check` | Keep platform/version validation and update flags. |

Laravel standards:

- Use `LoginRequest`, `ForgotPasswordRequest`, `UpdateProfileRequest`, and `AppVersionRequest`.
- Use `AppVersionData` and `AppVersionResource` for version payloads.
- Keep auth token generation through Sanctum.

Estimate: 1.5-2 days.

## 3. Catalog And Home APIs

| Method | Path | Auth | Controller | Response compatibility |
| --- | --- | --- | --- | --- |
| GET | `/api/home/stats` | Public | `HomeController@stats` | Keep `{ stats: { source, currency, changes, summary } }`. |
| GET | `/api/home/top_items` | Public with optional Sanctum user | `HomeController@topItems` | Keep `{ topItems: [...] }` after camelCase transform. |
| GET | `/api/car_groups` | Public | `CarGroupController@index` | Keep resource collection shape and nested parent behavior. |
| GET | `/api/items` | Public with optional Sanctum user | `ItemController@index` | Keep paginator shape, filters, sorting, and `savedItem`. |
| GET | `/api/items/{item}` | Public with optional Sanctum user | `ItemController@show` | Keep `{ data, related }`. |
| GET | `/api/items/{item}/similar` | Public with optional Sanctum user | `ItemController@similar` | Keep `{ data: [...] }`. |

Laravel standards:

- Use `ItemFilterRequest` for `perPage`, search, filters, sort, and relation filters.
- Use `ItemResource` and `CarGroupResource` as exact response shapers.
- Preserve media fields: `imageUrl`, `imageThumbUrl`, `imageDetailUrl`.
- Preserve `savedItem` default as `false` for guests.

Estimate: 2.5-3.5 days.

## 4. Metals, Market, And Calculator APIs

| Method | Path | Auth | Controller | Response compatibility |
| --- | --- | --- | --- | --- |
| POST | `/api/calculator/estimate` | Public | `CalculatorController@estimate` | Keep `{ estimate: ... }` and current validation errors. |
| GET | `/api/charts/metals` | Public | `MarketChartController@index` | Keep chart `data` structure and date/percent fields. |
| GET | `/api/notifications/changes` | Public | `MarketNotificationController@index` | Keep market change notification payload. |
| GET | `/api/v1/metals/spot` | Public | `MetalsController@index` | Keep `data`, `meta`, source, unit, currency, cache fields. |
| GET | `/api/v1/metals/spot/{key}` | Public | `MetalsController@show` | Keep single-metal payload and 404 shape. |
| POST | `/api/v1/metals/refresh` | Sanctum | `MetalsController@refresh` | Keep refresh success and 503 unavailable responses. |

Laravel standards:

- Keep business logic in `CalculatorService`, `ThirdPartyMarketService`, `MetalsSpotService`, and `MetalsResponseService`.
- Use `CalculatorEstimateRequest`, `MarketChangesRequest`, and `MetalsSpotRequest`.
- Keep explicit workflow routes because these are not CRUD resources.
- Preserve third-party-unavailable behavior with compatible 503 payloads.

Estimate: 3-4.5 days.

## 5. Saved Items And Notification Inbox

| Method | Path | Auth | Controller | Response compatibility |
| --- | --- | --- | --- | --- |
| GET | `/api/saved-items` | Sanctum | `SavedItemController@index` | Keep `{ data: [ItemResource...] }`. |
| POST | `/api/saved-items` | Sanctum | `SavedItemController@store` | Keep `itemId` input alias and success message. |
| DELETE | `/api/saved-items/{item}` | Sanctum | `SavedItemController@destroy` | Keep removal message. |
| GET | `/api/notifications` | Sanctum | `NotificationController@index` | Keep list plus unread count shape. |
| PATCH | `/api/notifications/read-all` | Sanctum | `NotificationController@markAllAsRead` | Keep bulk read message. |
| PATCH | `/api/notifications/{notification}/read` | Sanctum | `NotificationController@markAsRead` | Keep single notification data/message response. |
| POST | `/api/notifications/test-fcm` | Sanctum | `NotificationController@sendTestFcm` | Keep FCM test response and validation. |

Laravel standards:

- Use Sanctum route groups.
- Use `StoreSavedItemRequest` and `TestFcmNotificationRequest`.
- Keep notification database payload shape stable for the mobile inbox.
- Keep FCM token update behavior on login.

Estimate: 2-3 days.

## 6. Import And Duplicate Workflow

| Method | Path | Auth | Controller | Response compatibility |
| --- | --- | --- | --- | --- |
| POST | `/api/imports` | Sanctum | `ImportController@store` | Keep batch/report response and 201 status. |
| GET | `/api/imports/{batch}` | Sanctum | `ImportController@show` | Keep batch counters, status, and timestamps. |
| GET | `/api/imports/{batch}/duplicates` | Sanctum | `ImportController@duplicates` | Keep paginator and duplicate review row fields. |
| GET | `/api/imports/{batch}/issues` | Sanctum | `ImportController@issues` | Keep paginator and issue row fields. |
| PATCH | `/api/duplicates/{review}` | Sanctum | `ImportController@resolveDuplicate` | Keep `keep`, `overwrite`, `insert` action behavior. |

Laravel standards:

- Use `ImportExcelRequest` and `ResolveDuplicateRequest`.
- Keep orchestration in `ImportBatchService`.
- Keep parsing in import classes and format detector services.
- Keep long-running import work queued through `ImportBatchJob`.
- Preserve duplicate detection rules and invalid-row issue recording.

Estimate: 3-5 days.

## 7. Admin And Back-Office Parity

There are no public API endpoints in this section, but the new Laravel project must preserve the existing operational capabilities.

Implementation tasks:

- Rebuild Filament resources for users, app users, items, car groups, notification audiences, and admin notification campaigns.
- Preserve dashboard widgets for platform stats and metal trends.
- Preserve admin campaign creation and delivery logic.
- Recreate roles/permissions seeders and admin user seeding.
- Keep media upload handling compatible with current item images.

Estimate: 2-3.5 days.

## 8. Test Hardening And Cutover

Required tests:

- Contract tests for all 31 API routes.
- Auth tests for Sanctum-protected routes.
- Snapshot-style response tests for core frontend screens: home, item list, item detail, calculator, metals, saved items, notifications, imports.
- Unit tests for calculator math, metals response mapping, app version comparison, import format detection, and duplicate resolution.
- Postman collection run against staging after migration.

Cutover checklist:

- Run migrations against a staging clone.
- Run import smoke test with known Excel files.
- Compare current API and new API JSON responses for representative requests.
- Verify camelCase response transform is active globally for API routes.
- Verify public endpoints still work without auth.
- Verify optional auth on catalog endpoints still sets `savedItem` correctly.
- Verify FCM token registration during login.
- Verify 422, 404, and 503 payload shapes.

Estimate: 2.5-4 days.

## Endpoint Implementation Order

1. Foundation, middleware, migrations, seeders.
2. Auth and profile.
3. App version.
4. Car groups and items.
5. Home stats/top items.
6. Metals spot and market chart/change endpoints.
7. Calculator estimate.
8. Saved items.
9. Notifications and FCM.
10. Imports and duplicate resolution.
11. Filament admin parity.
12. Contract verification and staging cutover.

## Main Risks

- Pure PHP source may have implicit response fields not documented in `API_contract.md`. Mitigation: generate real response samples from the old system and use them as snapshot fixtures.
- Import behavior is data-sensitive and can drift by workbook format. Mitigation: replay the known workbook set and compare batch counts, issue counts, duplicate counts, and inserted rows.
- Metals provider behavior can change or go down. Mitigation: isolate upstream mapping in `MetalsSpotService` and preserve frontend-facing fallback/error payloads.
- CamelCase middleware is contract-critical. Mitigation: test nested arrays, pagination metadata, validation errors, and resource collections.
- Existing Composer scripts reference Laravel Boost. Mitigation: verify Boost is installed before enabling `boost:update` in the new project setup.

## Definition Of Done

- Every route listed by the current `php artisan route:list --path=api` exists in the new Laravel project.
- Current frontend can call the new backend without code changes.
- All contract tests pass.
- Staging responses match current production/staging examples for key frontend flows.
- Import workflow produces expected batch, duplicate, issue, and resolution results.
- Admin can manage the same operational resources through Filament.
