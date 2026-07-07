# Eresto Local Gate

Local Laravel service for syncing Eresto/XGym customer access data to Hikvision gate devices.

This service receives customer data from Eresto Cloud, builds Hikvision-compatible payloads, then sends:

- customer profile as `UserInfo`
- card credential as `CardInfo`
- optional face image data to Hikvision `FDLib`

## Main Flow

```text
Eresto Cloud
  -> POST /api/sync-customer
  -> HikvisionSyncController
  -> CustomerGatePayloadBuilder
  -> CustomerGateSyncService
  -> Hikvision gate devices
```

## Project Structure

```text
routes/api.php
  API route definitions.

app/Http/Controllers/Api/HikvisionSyncController.php
  Receives and validates sync customer requests.

app/Services/Hikvision/CustomerGatePayloadBuilder.php
  Builds Hikvision Person, Card, and face image payload data.

app/Services/Hikvision/CustomerGateSyncService.php
  Sends customer data to every configured Hikvision gate.

config/hikvision.php
  Hikvision device configuration.

tests/Feature/HikvisionSyncCustomerTest.php
  Mocked feature tests for the sync-customer endpoint.
```

## Requirements

- PHP 8.2+
- Composer
- Docker, if using Laravel Sail
- Hikvision gate device with ISAPI enabled

## Setup

Copy the environment file:

```bash
cp .env.example .env
```

Install dependencies:

```bash
composer install
```

Generate app key:

```bash
php artisan key:generate
```

If using Sail/Docker:

```bash
docker compose up -d
```

## Hikvision Configuration

Configure Hikvision devices in `.env`:

```env
HIKVISION_DEFAULT_DEVICE=xgym_entrance
HIKVISION_FORMAT=json
HIKVISION_PROTOCOL=http
HIKVISION_PORT=80
HIKVISION_USERNAME=your-device-username
HIKVISION_PASSWORD=your-device-password
HIKVISION_TIMEOUT=30
HIKVISION_VERIFY_SSL=false

HIKVISION_XGYM_ENTRANCE_IP=your-entrance-gate-ip
HIKVISION_XGYM_EXIT_IP=your-exit-gate-ip
```

Device list is defined in:

```text
config/hikvision.php
```

Current configured device keys:

- `xgym_entrance`
- `xgym_exit`

## API Endpoint

### Sync Customer To Gates

```http
POST /api/sync-customer
Content-Type: application/json
Accept: application/json
```

Example payload:

```json
{
  "member_id": "M-1260-VJIV",
  "name": "joookoo",
  "start_date": "2026-07-07T00:00:00",
  "end_date": "2026-12-31T23:59:59",
  "card_no": "CARD-M-1260-VJIV",
  "face_image_base64": "base64-image"
}
```

Example payload with multiple face images:

```json
{
  "member_id": "M-1260-VJIV",
  "name": "joookoo",
  "start_date": "2026-07-07T00:00:00",
  "end_date": "2026-12-31T23:59:59",
  "card_no": "CARD-M-1260-VJIV",
  "face_images_base64": [
    "base64-front",
    "base64-left",
    "base64-right"
  ]
}
```

Supported fields:

| Field | Required | Description |
| --- | --- | --- |
| `member_id` | yes | Customer/member identifier. Mapped to Hikvision `employeeNo`. |
| `name` | yes | Customer name. |
| `start_date` | conditional | Access start time. Required if `begin_time` is not sent. |
| `end_date` | conditional | Access end time. Required if `end_time` is not sent. |
| `begin_time` | conditional | Backward-compatible alias for `start_date`. |
| `end_time` | conditional | Backward-compatible alias for `end_date`. |
| `card_no` | no | Card credential number. Defaults to `member_id` if empty. |
| `face_image_base64` | no | Single face image in Base64 format. |
| `face_images_base64` | no | Multiple face images in Base64 format. Each image is uploaded separately. |
| `avatar_base64` | no | Backward-compatible fallback for single face image. |

Example response:

```json
{
  "message": "Sync process completed",
  "data": {
    "xgym_entrance": {
      "status": "success",
      "message": "Person and card credential synced successfully",
      "face_synced": true,
      "face_image_count": 1
    },
    "xgym_exit": {
      "status": "success",
      "message": "Person and card credential synced successfully",
      "face_synced": true,
      "face_image_count": 1
    }
  }
}
```

## Hikvision Endpoint Mapping

The service sends data to Hikvision through the `shaykhnazar/hikvision-isapi` package.

| Data | Hikvision ISAPI Endpoint |
| --- | --- |
| Customer/UserInfo | `POST /ISAPI/AccessControl/UserInfo/Record` |
| CardInfo | `POST /ISAPI/AccessControl/CardInfo/Record` |
| Face image | `POST /ISAPI/Intelligent/FDLib/1/picture` |

Face data uses Hikvision FDLib `1` by default.

## Face Image Notes

For POS-based face enrollment, Eresto Cloud may send one or more Base64 face images.

If `face_images_base64` contains multiple images, this service uploads each image to the same customer `employeeNo/member_id`. Hikvision stores and uses the face data inside the device-local FDLib.

This service treats face enrollment as customer-level data, not image-level data. The important result is whether the customer has usable face data on the device.

## Local Testing

Run the mocked feature test:

```bash
php artisan test tests/Feature/HikvisionSyncCustomerTest.php
```

With Docker/Sail:

```bash
docker compose exec -T laravel.test php artisan test tests/Feature/HikvisionSyncCustomerTest.php
```

The test uses a fake Hikvision HTTP client, so it does not require a real gate device.

## Manual Request Example

```bash
curl -X POST http://127.0.0.1/api/sync-customer \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "member_id": "TEST-001",
    "name": "Test Customer",
    "start_date": "2026-07-07T00:00:00",
    "end_date": "2026-12-31T23:59:59",
    "card_no": "CARD-TEST-001",
    "face_image_base64": "base64-image"
  }'
```

This manual request will call real configured Hikvision devices. Use the feature test if you want to test without connecting to a gate.

## Development Notes

- Do not edit files inside `vendor/` for project behavior.
- Keep controller logic thin. Put Hikvision payload building in `CustomerGatePayloadBuilder`.
- Put device communication logic in `CustomerGateSyncService`.
- Add tests when changing endpoint payload, card sync, or face sync behavior.
