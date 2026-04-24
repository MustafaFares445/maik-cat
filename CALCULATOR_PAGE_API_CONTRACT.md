# Calculator Page API Guide (App Integration)

Base URL: `/api`

## Purpose
This guide is for the mobile app calculator page only.
It explains:
- which endpoints to call
- the exact call order
- how each response field maps to this UI

Important:
- This page does not call any `/api/items` endpoint.
- This page does not send or require `itemId`.

## Endpoints Used

### 1) Load metals prices for table/chips/cards
`GET /api/v1/metals/spot?currency={EUR|USD}&unit=both&metals=platinum,palladium,rhodium`

Use this endpoint to fill:
- top metals table (`Pt`, `Pd`, `Rh`)
- change chips (`Rh: x%`, `Pd: x%`, `Pt: x%`)
- 3 price cards under calculator title (per gram values)

### 2) Calculate final price (manual inputs only)
`POST /api/calculator/estimate`

Body:
```json
{
  "weight": 0,
  "ptPpm": 0,
  "pdPpm": 0,
  "rhPpm": 0,
  "recoveryRate": 0,
  "currency": "EUR"
}
```

Use this endpoint to fill final result (`Price`).

## UI Mapping (Screen -> API)

| UI block | Source endpoint | Response/request fields |
| --- | --- | --- |
| Metals table rows | `GET /api/v1/metals/spot` | `data[].symbol`, `data[].priceOz`, `data[].priceGram` |
| Change chips (`Rh/Pd/Pt %`) | `GET /api/v1/metals/spot` | Match by `data[].key`, render `changePct` |
| 3 cards below title | `GET /api/v1/metals/spot` | `priceGram` for `rhodium`, `palladium`, `platinum` |
| Weight input (`g / oz`) | UI input | send as `weight` |
| PT/ppm input | UI input | send as `ptPpm` |
| PD/ppm input | UI input | send as `pdPpm` |
| RH/ppm input | UI input | send as `rhPpm` |
| Increase rate `%` input | UI input | send as `recoveryRate` |
| Currency dropdown | UI input | send as `currency` and also use for metals call |
| Final price (`Price`) | `POST /api/calculator/estimate` | `estimate.totalEur` when EUR, else `estimate.totalUsd` |

## Required Flow (Call Order)

1. On page open:
- call `GET /api/v1/metals/spot?currency={selectedCurrency}&unit=both&metals=platinum,palladium,rhodium`
- render table, chips, and cards

2. User enters calculator inputs:
- weight
- ptPpm/pdPpm/rhPpm
- recoveryRate
- currency

3. Calculate:
- call `POST /api/calculator/estimate` with all manual inputs
- render final result from response

## Re-fetch Triggers

- Currency changed:
  - re-call `GET /api/v1/metals/spot` with new currency
  - re-call `POST /api/calculator/estimate` with new currency

- Any calculator field changed (`weight`, `ptPpm`, `pdPpm`, `rhPpm`, `recoveryRate`):
  - re-call `POST /api/calculator/estimate`

## Input Rules (App)

- `recoveryRate` is decimal (0 to 1), not raw percent string.
- Convert from UI percent:
  - `98%` -> `0.98`
  - `0%` -> `0`
- All ppm and weight values are numeric.
- Send `currency` as `EUR` or `USD`.

## Error Handling UX

- Metals call fails:
  - keep last displayed metals if available
  - show non-blocking notice: "Prices temporarily unavailable"

- Estimate call fails:
  - keep previous total if available
  - show message in result area: "Cannot calculate now"

## Minimal Example Payloads

### Metals spot response
```json
{
  "success": true,
  "currency": "EUR",
  "data": [
    { "key": "platinum", "symbol": "Pt", "priceOz": 1864.94, "priceGram": 59.96, "changePct": 0.98 },
    { "key": "palladium", "symbol": "Pd", "priceOz": 1377.89, "priceGram": 44.30, "changePct": 0.98 },
    { "key": "rhodium", "symbol": "Rh", "priceOz": 9270.06, "priceGram": 298.02, "changePct": 0.90 }
  ]
}
```

### Estimate request
```json
{
  "weight": 182,
  "ptPpm": 120,
  "pdPpm": 350,
  "rhPpm": 12,
  "recoveryRate": 0.98,
  "currency": "EUR"
}
```

### Estimate response
```json
{
  "estimate": {
    "currency": "EUR",
    "totalEur": 4.69,
    "totalUsd": 5.10
  }
}
```
