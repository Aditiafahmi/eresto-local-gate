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
  -> SyncCustomerToGatesJob queue
  -> Queue worker / Horizon
  -> CustomerGatePayloadBuilder
  -> CustomerGateSyncService
  -> Hikvision gate devices
```

## Project Structure

```text
routes/api.php
  API route definitions.

app/Http/Controllers/Api/HikvisionSyncController.php
  Receives and validates sync customer requests, then queues Hikvision sync jobs.

app/Jobs/SyncCustomerToGatesJob.php
  Background job that builds the Hikvision payload and pushes it to configured gates.

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

## Queue Configuration

The `POST /api/sync-customer` endpoint is asynchronous. It validates the request, queues `SyncCustomerToGatesJob`, and returns `202 Accepted`. A queue worker then pushes the customer to Hikvision devices.

For simple local testing without Redis, use the sync queue:

```env
QUEUE_CONNECTION=sync
```

For Redis-backed queue processing:

```env
QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_QUEUE=hikvision-sync
```

Run a Laravel queue worker through Docker:

```bash
docker compose exec -T laravel.test php artisan queue:work redis --queue=hikvision-sync
```

When Horizon is installed in the real project, Horizon can run and monitor the same Redis queue:

```bash
docker compose exec -T laravel.test composer require laravel/horizon
docker compose exec -T laravel.test php artisan horizon:install
docker compose exec -T laravel.test php artisan horizon
```

To test Horizon locally without calling real Hikvision devices, dispatch safe demo jobs:

```bash
docker compose exec -T laravel.test php artisan queue:demo-horizon 30 --sleep=5
```

This sends 30 dummy jobs to the `hikvision-sync` queue. They only sleep briefly and write a small log entry, so they are safe for dashboard testing.

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
  "status": "active",
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
| `start_date` | yes | Customer access start time. |
| `end_date` | yes | Customer access end time. |
| `status` | no | Customer access status. Supported values: `active`, `inactive`. Defaults to `active`. |
| `card_no` | no | Card credential number. Defaults to `member_id` if empty. |
| `face_images_base64` | no | Multiple face images in Base64 format. Each image is uploaded separately. |

Example response:

```json
{
  "message": "Sync job queued",
  "member_id": "M-1260-VJIV"
}
```

The actual Hikvision push happens in the queue worker. In testing, `QUEUE_CONNECTION=sync` executes the job immediately.

### Update Customer Access Period

Used when Eresto Cloud needs to update an existing customer name or access validity period on every configured gate.

```http
PATCH /api/sync-customer/{member_id}
Content-Type: application/json
Accept: application/json
```

Example payload:

```json
{
  "name": "Updated Customer",
  "start_date": "2026-08-01T00:00:00",
  "end_date": "2027-08-01T23:59:59",
  "status": "inactive"
}
```

This endpoint updates Hikvision `UserInfo` only. It does not re-upload card or face data. `status=inactive` disables the Hikvision validity flag.

### Delete Customer From Gates

Used when Eresto Cloud needs to remove a customer credential from every configured gate.

```http
DELETE /api/sync-customer/{member_id}
Accept: application/json
```

This endpoint deletes Hikvision `CardInfo` first, then deletes `UserInfo`.

Face cleanup depends on the Hikvision device behavior. The current upload flow links face data through `employeeNo`, and the package does not expose a safe delete-by-employeeNo face method.

## Hikvision Endpoint Mapping

The service sends data to Hikvision through the `shaykhnazar/hikvision-isapi` package.

| Data | Hikvision ISAPI Endpoint |
| --- | --- |
| Customer/UserInfo | `POST /ISAPI/AccessControl/UserInfo/Record` |
| Customer/UserInfo update | `PUT /ISAPI/AccessControl/UserInfo/Modify` |
| Customer/UserInfo delete | `PUT /ISAPI/AccessControl/UserInfo/Delete` |
| CardInfo | `POST /ISAPI/AccessControl/CardInfo/Record` |
| CardInfo delete | `PUT /ISAPI/AccessControl/CardInfo/Delete` |
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
    "face_images_base64": ["base64-image"]
  }'
```

This manual request queues a Hikvision sync job. If `QUEUE_CONNECTION=sync`, the request will call real configured Hikvision devices before responding. If `QUEUE_CONNECTION=redis` or `database`, make sure a queue worker is running.

## Development Notes

- Do not edit files inside `vendor/` for project behavior.
- Keep controller logic thin. Queue heavy sync work through jobs.
- Put Hikvision payload building in `CustomerGatePayloadBuilder`.
- Put device communication logic in `CustomerGateSyncService`.
- Add tests when changing endpoint payload, card sync, or face sync behavior.
