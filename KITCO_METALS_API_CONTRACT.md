# Kitco-Backed Metals API Contract

This contract covers API endpoints powered by `App\Services\Mobile\MetalsSpotService`, which now fetches from Kitco first and falls back to Metals Live when needed.

Base URL: `/api`

## 1) Get Metals Spot List

- Method: `GET`
- Path: `/v1/metals/spot`
- Auth: Public

### Query Params

- `currency` (optional): `USD` or `EUR` (case-insensitive), default `USD`
- `unit` (optional): `oz`, `gram`, or `both`, default `both`
- `metals` (optional): comma-separated keys, e.g. `platinum,palladium,rhodium`

### Example Request

```http
GET /api/v1/metals/spot?currency=USD&unit=both&metals=platinum,palladium,rhodium
Accept: application/json
```

### Example 200 Response (Kitco source)

```json
{
  "success": true,
  "source": "kitco.com",
  "cached": false,
  "cache_expires_at": "2026-05-09T12:00:00+03:00",
  "updated_at": "2026-05-09T06:00:00+00:00",
  "currency": "USD",
  "fx_rate": 1,
  "data": [
    {
      "key": "platinum",
      "name_en": "Platinum",
      "name_ar": "platinum_ar",
      "symbol": "Pt",
      "price_oz": 982.4,
      "price_gram": 31.58,
      "change_oz": 11.79,
      "change_pct": 1.2,
      "direction": "up"
    },
    {
      "key": "palladium",
      "name_en": "Palladium",
      "name_ar": "palladium_ar",
      "symbol": "Pd",
      "price_oz": 1012.1,
      "price_gram": 32.54,
      "change_oz": -3.2,
      "change_pct": -0.32,
      "direction": "down"
    },
    {
      "key": "rhodium",
      "name_en": "Rhodium",
      "name_ar": "rhodium_ar",
      "symbol": "Rh",
      "price_oz": 4400,
      "price_gram": 141.46,
      "change_oz": 0,
      "change_pct": 0,
      "direction": "flat"
    }
  ]
}
```

### Example 503 Response

```json
{
  "success": false,
  "error": "upstream_unavailable",
  "message": "All price sources are currently unreachable.",
  "retry_after": 300
}
```

## 2) Get Single Metal Spot

- Method: `GET`
- Path: `/v1/metals/spot/{key}`
- Auth: Public

### Path Params

- `key`: one of `gold`, `silver`, `platinum`, `palladium`, `rhodium`

### Query Params

- `currency` (optional): `USD` or `EUR`, default `USD`

### Example Request

```http
GET /api/v1/metals/spot/platinum?currency=EUR
Accept: application/json
```

### Example 200 Response

```json
{
  "success": true,
  "source": "kitco.com",
  "cached": true,
  "currency": "EUR",
  "fx_rate": 0.921345,
  "data": {
    "key": "platinum",
    "name_en": "Platinum",
    "name_ar": "platinum_ar",
    "symbol": "Pt",
    "price_oz": 905.15,
    "price_gram": 29.1,
    "change_oz": 10.86,
    "change_pct": 1.2,
    "direction": "up"
  }
}
```

### Example 404 Response

```json
{
  "success": false,
  "error": "not_found",
  "message": "Metal 'unobtainium' is not supported."
}
```

### Example 503 Response

```json
{
  "success": false,
  "error": "upstream_unavailable",
  "message": "All price sources are currently unreachable.",
  "retry_after": 300
}
```

## 3) Refresh Metals Cache

- Method: `POST`
- Path: `/v1/metals/refresh`
- Auth: Required (`Authorization: Bearer <sanctum_token>`)

### Request Body

- None

### Example Request

```http
POST /api/v1/metals/refresh
Accept: application/json
Authorization: Bearer <token>
```

### Example 200 Response

```json
{
  "success": true,
  "message": "Cache cleared. Fresh data fetched.",
  "source": "kitco.com",
  "updated_at": "2026-05-09T06:00:00+00:00"
}
```

### Example 503 Response

```json
{
  "success": false,
  "error": "upstream_unavailable",
  "message": "All price sources are currently unreachable.",
  "retry_after": 300
}
```

## Source Field Values

- `kitco.com`: data fetched from Kitco (primary)
- `api.metals.live`: Kitco failed, fallback source used
- `stale_cache`: live providers failed, stale cache returned
- `hard_fallback`: hardcoded fallback returned
