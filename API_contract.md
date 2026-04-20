# API Contract

Base URL: `/api`

Environments:
- local: `http://maik-cars.test`
- dev: `https://maik-cat.mustafafares.com`

Authentication:
- Public endpoints do not require auth.
- Import, duplicate-resolution, saved-items, profile, auth logout, and user notifications endpoints require Sanctum bearer auth.

Response format:
- API responses are converted to camelCase by middleware.
- Query and JSON request keys may be sent as camelCase; they are normalized server-side.
- Item payloads now include `savedItem` in all GET item endpoints. Default is `false` for guests.

## Auth A. Login

`POST /api/auth/login`

Body props:

| Name | Type | Required | Example |
| --- | --- | --- | --- |
| email | string | Yes | admin@example.com |
| password | string | Yes | secret123 |

Example response:

```json
{
  "token": "1|plain-text-token",
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "admin@example.com"
  }
}
```

Errors:
- 422 when credentials are invalid.

## Auth B. Logout

`POST /api/auth/logout`

Auth: Sanctum bearer token required.

Example response:

```json
{
  "message": "Logged out successfully."
}
```

## Auth C. Forget password

`POST /api/auth/forgot-password`

Body props:

| Name | Type | Required | Example |
| --- | --- | --- | --- |
| email | string | Yes | admin@example.com |

Example response:

```json
{
  "message": "If the account exists, a password reset link was sent."
}
```

## Profile A. Get profile

`GET /api/profile`

Auth: Sanctum bearer token required.

Example response:

```json
{
  "data": {
    "id": 1,
    "name": "Admin",
    "email": "admin@example.com"
  }
}
```

## Profile B. Update profile

`PATCH /api/profile`

Auth: Sanctum bearer token required.

Body props:

| Name | Type | Required | Example |
| --- | --- | --- | --- |
| name | string | Yes | Admin User |
| email | string | Yes | admin@example.com |

Example response:

```json
{
  "message": "Profile updated successfully.",
  "data": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@example.com"
  }
}
```

## Saved items A. List

`GET /api/saved-items`

Auth: Sanctum bearer token required.

Example response:

```json
{
  "data": [
    {
      "id": "2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb",
      "model": "BMW 520D",
      "serialCode": "7832440",
      "weightKg": 0.182,
      "ptPpm": 120,
      "pdPpm": 350,
      "rhPpm": 12,
      "shapeCode": "A1",
      "details": "Turbo converter",
      "carGroup": {
        "id": "d9d1c8c7-8f70-4f3d-9e3f-2cfd6069b9c1",
        "name": "BMW",
        "region": "EU",
        "parentId": null
      },
      "extraCodes": ["BMW-01"],
      "imageUrl": null,
      "savedItem": true
    }
  ]
}
```

## Saved items B. Store

`POST /api/saved-items`

Auth: Sanctum bearer token required.

Body props:

| Name | Type | Required | Example | Notes |
| --- | --- | --- | --- | --- |
| itemId | uuid | Yes | 2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb | Alias: item_id |

Example response:

```json
{
  "message": "Item saved successfully."
}
```

## Saved items C. Delete

`DELETE /api/saved-items/{item}`

Auth: Sanctum bearer token required.

Example response:

```json
{
  "message": "Item removed from saved list."
}
```

## 1. Home stats

`GET /api/home/stats`

Query props:

| Name | Type | Required | Example | Notes |
| --- | --- | --- | --- | --- |
| days | integer | No | 14 | Min 1, max 14. Default 14. |
| currency | string | No | USD | Accepted: USD, EUR. Default USD. |

Example response:

```json
{
  "stats": {
    "source": "third_party",
    "currency": "USD",
    "changes": [
      {
        "date": "2026-04-17",
        "ptUsdPerOz": 1512.45,
        "pdUsdPerOz": 1024.1,
        "rhUsdPerOz": 4380.75,
        "ptChangePercent": 1.2,
        "pdChangePercent": -0.4,
        "rhChangePercent": 0.8
      }
    ],
    "summary": {
      "date": "2026-04-17",
      "ptUsdPerOz": 1512.45,
      "pdUsdPerOz": 1024.1,
      "rhUsdPerOz": 4380.75,
      "ptEurPerOz": null,
      "pdEurPerOz": null,
      "rhEurPerOz": null,
      "ptChangePercent": 1.2,
      "pdChangePercent": -0.4,
      "rhChangePercent": 0.8,
      "currency": "USD",
      "fxRate": 1
    }
  }
}
```

## 2. Home top items

`GET /api/home/top_items`

Example response:

```json
{
  "topItems": [
    {
      "id": "2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb",
      "model": "BMW 520D",
      "serialCode": "7832440",
      "weightKg": 0.182,
      "ptPpm": 120,
      "pdPpm": 350,
      "rhPpm": 12,
      "shapeCode": "A1",
      "details": "Turbo converter",
      "carGroup": {
        "id": "d9d1c8c7-8f70-4f3d-9e3f-2cfd6069b9c1",
        "name": "BMW",
        "region": "EU",
        "parentId": null
      },
      "extraCodes": ["BMW-01", "OE-7"],
      "imageUrl": null,
      "savedItem": false
    }
  ]
}
```

## 3. Car groups

`GET /api/car_groups`

Example response:

```json
{
  "data": [
    {
      "id": "d9d1c8c7-8f70-4f3d-9e3f-2cfd6069b9c1",
      "name": "BMW",
      "region": "EU",
      "parentId": null
    }
  ]
}
```

## 4. Items list

`GET /api/items`

Query props:

| Name | Type | Required | Example | Notes |
| --- | --- | --- | --- | --- |
| perPage | integer | No | 20 | Min 1, max 100. Default 20. |
| text | string | No | PR-ALT-01 | Searches serial code, model, and extra codes. |
| categoryId | uuid | No | d9d1c8c7-8f70-4f3d-9e3f-2cfd6069b9c1 | Alias: category_id. |
| carGroup | string | No | BMW | Alias: car_group. Can be group id, name, or sheet name. |
| sort | string | No | -created_at | Allowed: created_at, -created_at, serial_code, -serial_code, model, -model. |

Example response:

```json
{
  "data": [
    {
      "id": "2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb",
      "model": "BMW 520D",
      "serialCode": "7832440",
      "weightKg": 0.182,
      "ptPpm": 120,
      "pdPpm": 350,
      "rhPpm": 12,
      "shapeCode": "A1",
      "details": "Turbo converter",
      "carGroup": {
        "id": "d9d1c8c7-8f70-4f3d-9e3f-2cfd6069b9c1",
        "name": "BMW",
        "region": "EU",
        "parentId": null
      },
      "extraCodes": ["BMW-01"],
      "imageUrl": null,
      "savedItem": false
    }
  ],
  "links": {
    "first": "https://example.test/api/items?page=1",
    "last": "https://example.test/api/items?page=12",
    "prev": null,
    "next": "https://example.test/api/items?page=2"
  },
  "meta": {
    "currentPage": 1,
    "from": 1,
    "lastPage": 12,
    "path": "https://example.test/api/items",
    "perPage": 20,
    "to": 20,
    "total": 240
  }
}
```

## 5. Item details

`GET /api/items/{item}`

Path props:

| Name | Type | Required | Example |
| --- | --- | --- | --- |
| item | uuid | Yes | 2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb |

Example response:

```json
{
  "data": {
    "id": "2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb",
    "model": "BMW 520D",
    "serialCode": "7832440",
    "weightKg": 0.182,
    "ptPpm": 120,
    "pdPpm": 350,
    "rhPpm": 12,
    "shapeCode": "A1",
    "details": "Turbo converter",
    "carGroup": {
      "id": "d9d1c8c7-8f70-4f3d-9e3f-2cfd6069b9c1",
      "name": "BMW",
      "region": "EU",
      "parentId": null
    },
    "extraCodes": ["BMW-01", "OE-7"],
    "imageUrl": null,
    "savedItem": false
  },
  "related": [
    {
      "id": "5db02e7e-9142-47a6-b040-7df4a9b78a2c",
      "model": "BMW 530D",
      "serialCode": "7832441",
      "weightKg": 0.175,
      "ptPpm": 110,
      "pdPpm": 300,
      "rhPpm": 10,
      "shapeCode": "A1",
      "details": null,
      "carGroup": {
        "id": "d9d1c8c7-8f70-4f3d-9e3f-2cfd6069b9c1",
        "name": "BMW",
        "region": "EU",
        "parentId": null
      },
      "extraCodes": [],
      "imageUrl": null,
      "savedItem": false
    }
  ]
}
```

## 6. Similar items

`GET /api/items/{item}/similar`

Query props:

| Name | Type | Required | Example | Notes |
| --- | --- | --- | --- | --- |
| limit | integer | No | 8 | Min 1, max 20. Default 8. |

Example response:

```json
{
  "data": [
    {
      "id": "5db02e7e-9142-47a6-b040-7df4a9b78a2c",
      "model": "BMW 530D",
      "serialCode": "7832441",
      "weightKg": 0.175,
      "ptPpm": 110,
      "pdPpm": 300,
      "rhPpm": 10,
      "shapeCode": "A1",
      "details": null,
      "carGroup": {
        "id": "d9d1c8c7-8f70-4f3d-9e3f-2cfd6069b9c1",
        "name": "BMW",
        "region": "EU",
        "parentId": null
      },
      "extraCodes": [],
      "imageUrl": null,
      "savedItem": false
    }
  ]
}
```

## 7. Calculator estimate

`POST /api/calculator/estimate`

Body props:

| Name | Type | Required | Example | Notes |
| --- | --- | --- | --- | --- |
| itemId | uuid | Yes | 2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb | Required. |
| recoveryRate | number | No | 0.8 | Min 0, max 1. Default 0.8. |
| currency | string | No | USD | Accepted: USD, EUR. Default USD. |

Example request body:

```json
{
  "itemId": "2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb",
  "recoveryRate": 0.8,
  "currency": "USD"
}
```

Example response:

```json
{
  "item": {
    "id": "2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb",
    "serialCode": "7832440",
    "model": "BMW 520D"
  },
  "estimate": {
    "recoveryRate": 0.8,
    "currency": "USD",
    "fxRate": 1,
    "weightKg": 0.182,
    "breakdown": {
      "pt": {
        "grams": 0.02184,
        "usdPerGram": 48.59,
        "valueUsd": 0.85,
        "eurPerGram": null,
        "valueEur": null
      },
      "pd": {
        "grams": 0.0637,
        "usdPerGram": 32.89,
        "valueUsd": 1.68,
        "eurPerGram": null,
        "valueEur": null
      },
      "rh": {
        "grams": 0.002184,
        "usdPerGram": 141.07,
        "valueUsd": 0.25,
        "eurPerGram": null,
        "valueEur": null
      }
    },
    "totalUsd": 2.78,
    "totalEur": null,
    "priceReference": {
      "id": "7e1de0ca-3f0d-4311-8d73-1a4bdc6c8d66",
      "fetchedAt": "2026-04-18T10:15:00Z"
    }
  }
}
```

Errors:
- 422 when no metal price is available.
- 422 when `itemId` is missing or invalid.

## 8. Metals market chart

`GET /api/charts/metals`

Query props:

| Name | Type | Required | Example | Notes |
| --- | --- | --- | --- | --- |
| days | integer | No | 14 | Min 1, max 14. Default 14. |
| currency | string | No | USD | Accepted: USD, EUR. Default USD. |

Example response:

```json
{
  "period": "14_days",
  "currency": "USD",
  "points": [
    {
      "date": "2026-04-17",
      "ptUsdPerOz": 1512.45,
      "pdUsdPerOz": 1024.1,
      "rhUsdPerOz": 4380.75,
      "ptChangePercent": 1.2,
      "pdChangePercent": -0.4,
      "rhChangePercent": 0.8
    }
  ]
}
```

## 9. Market notifications

`GET /api/notifications/changes`

Query props:

| Name | Type | Required | Example | Notes |
| --- | --- | --- | --- | --- |
| days | integer | No | 14 | Min 1, max 14. Default 14. |
| currency | string | No | USD | Accepted: USD, EUR. Default USD. |

Example response:

```json
{
  "currency": "USD",
  "data": [
    {
      "id": "2026-04-17-0",
      "type": "market_change",
      "title": "Metal prices updated",
      "body": "Pt 1.20%, Pd -0.40%, Rh 0.80%",
      "date": "2026-04-17",
      "meta": {
        "ptUsdPerOz": 1512.45,
        "pdUsdPerOz": 1024.1,
        "rhUsdPerOz": 4380.75,
        "ptEurPerOz": null,
        "pdEurPerOz": null,
        "rhEurPerOz": null,
        "currency": "USD",
        "fxRate": 1
      }
    }
  ]
}
```

## 10. Metals spot list

`GET /api/v1/metals/spot`

Query props:

| Name | Type | Required | Example | Notes |
| --- | --- | --- | --- | --- |
| currency | string | No | USD | Accepted: USD, EUR. Default USD. |
| unit | string | No | both | Accepted: oz, gram, both. Default both. |
| metals | string | No | gold,platinum | Comma-separated list of metal keys. |

Example response:

```json
{
  "success": true,
  "source": "kitco.com",
  "cached": true,
  "cacheExpiresAt": "2026-04-18T16:15:00Z",
  "updatedAt": "2026-04-18T10:15:00Z",
  "currency": "USD",
  "fxRate": 1,
  "data": [
    {
      "key": "platinum",
      "nameEn": "Platinum",
      "nameAr": "بلاتين",
      "symbol": "Pt",
      "priceOz": 982.4,
      "priceGram": 31.58,
      "changeOz": 11.79,
      "changePct": 1.2,
      "direction": "up"
    }
  ]
}
```

Errors:
- 503 when all metal price sources are unavailable.

## 11. Single metal spot

`GET /api/v1/metals/spot/{key}`

Path props:

| Name | Type | Required | Example |
| --- | --- | --- | --- |
| key | string | Yes | platinum |

Query props:

| Name | Type | Required | Example | Notes |
| --- | --- | --- | --- | --- |
| currency | string | No | USD | Accepted: USD, EUR. Default USD. |

Example response:

```json
{
  "success": true,
  "source": "kitco.com",
  "cached": true,
  "currency": "USD",
  "fxRate": 1,
  "data": {
    "key": "platinum",
    "nameEn": "Platinum",
    "nameAr": "بلاتين",
    "symbol": "Pt",
    "priceOz": 982.4,
    "priceGram": 31.58,
    "changeOz": 11.79,
    "changePct": 1.2,
    "direction": "up"
  }
}
```

Errors:
- 404 when the metal key is not supported.

## 12. Metals refresh

`POST /api/v1/metals/refresh`

Auth: Sanctum bearer token required.

Example response:

```json
{
  "success": true,
  "message": "Cache cleared. Fresh data fetched.",
  "source": "api.metals.live",
  "updatedAt": "2026-04-18T10:15:00Z"
}
```

Errors:
- 503 when all metal price sources are unavailable.

## 13. Import batch create

`POST /api/imports`

Auth: Sanctum bearer token required.

Body props:

| Name | Type | Required | Example | Notes |
| --- | --- | --- | --- | --- |
| file | file | Yes | converters.xlsx | Accepted: .xlsx, .xls. Max 20 MB. |

Example request body:

```json
{
  "file": "multipart/form-data upload"
}
```

Example response:

```json
{
  "batchId": "7e1de0ca-3f0d-4311-8d73-1a4bdc6c8d66",
  "status": "completed",
  "rowsInserted": 120,
  "rowsSkipped": 4,
  "rowsFlagged": 8,
  "rowsInvalid": 2
}
```

Errors:
- 422 when the file is missing or not an Excel file.

## 14. Import batch details

`GET /api/imports/{batch}`

Auth: Sanctum bearer token required.

Path props:

| Name | Type | Required | Example |
| --- | --- | --- | --- |
| batch | uuid | Yes | 7e1de0ca-3f0d-4311-8d73-1a4bdc6c8d66 |

Example response:

```json
{
  "id": "7e1de0ca-3f0d-4311-8d73-1a4bdc6c8d66",
  "fileName": "converters.xlsx",
  "importedBy": "admin@example.com",
  "status": "completed",
  "errorMessage": null,
  "rowsInserted": 120,
  "rowsSkipped": 4,
  "rowsFlagged": 8,
  "rowsInvalid": 2,
  "duplicateReviews": [
    {
      "id": "c7b771f4-7c29-42c0-8b0e-245f205f1f7a",
      "batchId": "7e1de0ca-3f0d-4311-8d73-1a4bdc6c8d66",
      "excelRow": 42,
      "excelSheet": "BMW",
      "payload": {
        "model": "BMW 520D",
        "serialCode": "7832440",
        "weightKg": 0.182,
        "ptPpm": 120,
        "pdPpm": 350,
        "rhPpm": 12,
        "extraCodes": "BMW-01",
        "details": "Turbo converter",
        "shapeCode": "A1"
      },
      "existingItemId": "2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb",
      "status": "pending",
      "resolvedBy": null,
      "resolvedAt": null
    }
  ]
}
```

## 15. Import duplicate queue

`GET /api/imports/{batch}/duplicates`

Auth: Sanctum bearer token required.

Path props:

| Name | Type | Required | Example |
| --- | --- | --- | --- |
| batch | uuid | Yes | 7e1de0ca-3f0d-4311-8d73-1a4bdc6c8d66 |

Example response:

```json
{
  "currentPage": 1,
  "data": [
    {
      "id": "c7b771f4-7c29-42c0-8b0e-245f205f1f7a",
      "batchId": "7e1de0ca-3f0d-4311-8d73-1a4bdc6c8d66",
      "excelRow": 42,
      "excelSheet": "BMW",
      "payload": {
        "model": "BMW 520D",
        "serialCode": "7832440",
        "weightKg": 0.182,
        "ptPpm": 120,
        "pdPpm": 350,
        "rhPpm": 12,
        "extraCodes": "BMW-01",
        "details": "Turbo converter",
        "shapeCode": "A1"
      },
      "existingItemId": "2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb",
      "status": "pending",
      "resolvedBy": null,
      "resolvedAt": null,
      "existingItem": {
        "id": "2b87a4d7-8e2f-4d2d-bdf5-7ce31c14a2bb",
        "model": "BMW 520D",
        "serialCode": "7832440",
        "carGroup": {
          "id": "d9d1c8c7-8f70-4f3d-9e3f-2cfd6069b9c1",
          "name": "BMW",
          "region": "EU",
          "parentId": null
        }
      }
    }
  ],
  "firstPageUrl": "https://example.test/api/imports/7e1de0ca-3f0d-4311-8d73-1a4bdc6c8d66/duplicates?page=1",
  "from": 1,
  "lastPage": 1,
  "lastPageUrl": "https://example.test/api/imports/7e1de0ca-3f0d-4311-8d73-1a4bdc6c8d66/duplicates?page=1",
  "links": [],
  "nextPageUrl": null,
  "path": "https://example.test/api/imports/7e1de0ca-3f0d-4311-8d73-1a4bdc6c8d66/duplicates",
  "perPage": 50,
  "prevPageUrl": null,
  "to": 1,
  "total": 1
}
```

## 16. Resolve duplicate review

`PATCH /api/duplicates/{review}`

Auth: Sanctum bearer token required.

Path props:

| Name | Type | Required | Example |
| --- | --- | --- | --- |
| review | uuid | Yes | c7b771f4-7c29-42c0-8b0e-245f205f1f7a |

Body props:

| Name | Type | Required | Example | Notes |
| --- | --- | --- | --- | --- |
| action | string | Yes | keep | Allowed: keep, overwrite, insert. |

Example request body:

```json
{
  "action": "keep"
}
```

Example response:

```json
{
  "id": "c7b771f4-7c29-42c0-8b0e-245f205f1f7a",
  "status": "kept",
  "resolvedBy": "admin@example.com",
  "resolvedAt": "2026-04-18T10:15:00Z"
}
```

Errors:
- 422 when `action` is missing or invalid.

## 17. User notifications list

`GET /api/notifications`

Auth: Sanctum bearer token required.

Example response:

```json
{
  "data": [
    {
      "id": "0a005136-8bc7-41ea-a80c-bd51671ec34f",
      "type": "App\\Notifications\\ExampleNotification",
      "notifiableType": "App\\Models\\User",
      "notifiableId": "30e8bb6f-6714-4bb1-981f-d9f4ca551f92",
      "data": {
        "title": "New price update",
        "body": "Platinum moved up"
      },
      "readAt": null,
      "createdAt": "2026-04-18T09:10:00Z",
      "updatedAt": "2026-04-18T09:10:00Z"
    }
  ],
  "unreadCount": 1
}
```

## 18. Mark all notifications as read

`PATCH /api/notifications/read-all`

Auth: Sanctum bearer token required.

Example response:

```json
{
  "message": "Notifications marked as read.",
  "unreadCount": 0
}
```

## 19. Mark single notification as read

`PATCH /api/notifications/{notification}/read`

Auth: Sanctum bearer token required.

Path props:

| Name | Type | Required | Example |
| --- | --- | --- | --- |
| notification | uuid | Yes | 0a005136-8bc7-41ea-a80c-bd51671ec34f |

Example response:

```json
{
  "message": "Notification marked as read.",
  "data": {
    "id": "0a005136-8bc7-41ea-a80c-bd51671ec34f",
    "type": "App\\Notifications\\ExampleNotification",
    "notifiableType": "App\\Models\\User",
    "notifiableId": "30e8bb6f-6714-4bb1-981f-d9f4ca551f92",
    "data": {
      "title": "New price update"
    },
    "readAt": "2026-04-18T10:15:00Z",
    "createdAt": "2026-04-18T09:10:00Z",
    "updatedAt": "2026-04-18T10:15:00Z"
  }
}
```

Errors:
- 404 when the notification does not belong to the authenticated user.
