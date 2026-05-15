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
  "weightUnit": "g",
  "ptPpm": 0,
  "pdPpm": 0,
  "rhPpm": 0,
  "ptUsdPerGram": 0,
  "pdUsdPerGram": 0,
  "rhUsdPerGram": 0,
  "ptRate": 0,
  "pdRate": 0,
  "rhRate": 0,
  "humidityRate": 0,
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
| Weight unit selector (`g / kg`) | UI input | send as `weightUnit` (`g` or `kg`) |
| PT/ppm input | UI input | send as `ptPpm` |
| PD/ppm input | UI input | send as `pdPpm` |
| RH/ppm input | UI input | send as `rhPpm` |
| PT rate `%` chip/input | UI input | send as `ptRate` |
| PD rate `%` chip/input | UI input | send as `pdRate` |
| RH rate `%` chip/input | UI input | send as `rhRate` |
| Moisture/Humidity `%` input | UI input | send as `humidityRate` |
| PT card price (USD) | UI input or loaded card | send as `ptUsdPerGram` |
| PD card price (USD) | UI input or loaded card | send as `pdUsdPerGram` |
| RH card price (USD) | UI input or loaded card | send as `rhUsdPerGram` |
| Currency dropdown | UI input | send as `currency` and also use for metals call |
| Final price (`Price`) | `POST /api/calculator/estimate` | `estimate.totalEur` when EUR, else `estimate.totalUsd` |

## Required Flow (Call Order)

1. On page open:
- call `GET /api/v1/metals/spot?currency={selectedCurrency}&unit=both&metals=platinum,palladium,rhodium`
- render table, chips, and cards

2. User enters calculator inputs:
- weight
- weightUnit
- ptPpm/pdPpm/rhPpm
- ptRate/pdRate/rhRate
- humidityRate
- ptUsdPerGram/pdUsdPerGram/rhUsdPerGram
- currency

3. Calculate:
- call `POST /api/calculator/estimate` with all manual inputs
- render final result from response

## Re-fetch Triggers

- Currency changed:
  - re-call `GET /api/v1/metals/spot` with new currency
  - re-call `POST /api/calculator/estimate` with new currency

- Any calculator field changed (`weight`, `weightUnit`, `ptPpm`, `pdPpm`, `rhPpm`, `ptRate`, `pdRate`, `rhRate`, `humidityRate`, `ptUsdPerGram`, `pdUsdPerGram`, `rhUsdPerGram`):
  - re-call `POST /api/calculator/estimate`

## Input Rules (App)

- `ptRate`, `pdRate`, `rhRate`, and `humidityRate` can be sent as:
  - decimals (`0` to `1`), for example `0.98`, `0.5`
  - whole percentages (`0` to `100`), for example `98`, `50`
- Backend normalization:
  - `98` -> `0.98`
  - `50` -> `0.5`
  - `0.98` stays `0.98`
- Recovery-rate compatibility:
  - if rate arrives as percent-fraction (`0.0098` for `0.98%`), backend normalizes it to `0.98`
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
  "weightUnit": "g",
  "ptPpm": 120,
  "pdPpm": 350,
  "rhPpm": 12,
  "ptRate": 0.98,
  "pdRate": 0.98,
  "rhRate": 0.90,
  "humidityRate": 0.05,
  "ptUsdPerGram": 59.96,
  "pdUsdPerGram": 44.30,
  "rhUsdPerGram": 298.02,
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
