# Eresto Local Gate

Local Laravel service for syncing Eresto/XGym customer access data to Hikvision gate devices.

This service receives customer data from Eresto Cloud, builds Hikvision-compatible payloads, then sends:

- customer profile as `UserInfo`
- card credential as `CardInfo`
- optional face image data to Hikvision `FDLib`

## Main Flow

```text
Eresto Cloud
  -> POST /cloud/webhook { event, member_id }
  -> CloudWebhookController
  -> SyncCustomerToGatesJob queue
  -> Queue worker / Horizon
  -> CloudCustomerClient GET /api/customers/{member_id}
  -> CustomerGatePayloadBuilder
  -> CustomerGateSyncService
  -> Hikvision gate devices
```

## Project Structure

```text
routes/api.php
  Manual API route definitions.

routes/web.php
  Local server webhook route definitions.

app/Http/Controllers/CloudWebhookController.php
  Receives cloud customer events, validates optional HMAC signature, and queues sync jobs.

app/Http/Controllers/Api/HikvisionSyncController.php
  Receives and validates manual full-payload sync requests, then queues Hikvision sync jobs.

app/Jobs/SyncCustomerToGatesJob.php
  Background job that fetches customer data when needed, builds the Hikvision payload, and pushes it to configured gates.

app/Services/Cloud/CloudCustomerClient.php
  Fetches customer records from Eresto Cloud API.

app/Services/Hikvision/CustomerGatePayloadBuilder.php
  Builds Hikvision Person, Card, and face image payload data.

app/Services/Hikvision/CustomerGateSyncService.php
  Sends customer data to every configured Hikvision gate.

config/hikvision.php
  Hikvision device configuration.

tests/Feature/HikvisionSyncCustomerTest.php
  Mocked feature tests for webhook and sync-customer endpoints.
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

## Eresto Cloud Configuration

Configure the Cloud API base URL and optional webhook secret in `.env`:

```env
ERESTO_CLOUD_URL=https://cloud-api.example.com
ERESTO_CLOUD_TOKEN=
ERESTO_CLOUD_TIMEOUT=10
ERESTO_CLOUD_WEBHOOK_SECRET=
```

If `ERESTO_CLOUD_WEBHOOK_SECRET` is set, incoming `/cloud/webhook` requests must include one of these headers:

```text
X-Hub-Signature: sha256=<hmac>
X-Hub-Signature-256: sha256=<hmac>
```

The HMAC is calculated from the raw request body using SHA-256 and the shared secret.

## Queue Configuration

The `/cloud/webhook` and `/api/sync-customer` endpoints are asynchronous. They validate the request, queue `SyncCustomerToGatesJob`, and return `202 Accepted`. A queue worker then pushes the customer to Hikvision devices.

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

## Local Server Endpoints

### Cloud Webhook

Main workflow endpoint based on the senior design.

```http
POST /cloud/webhook
Content-Type: application/json
Accept: application/json
```

Example payload:

```json
{
  "event": "customer.updated",
  "member_id": "M-1260-VJIV"
}
```

Supported events:

| Event | Behavior |
| --- | --- |
| `customer.created` | Fetch full customer data from Cloud API, then idempotently sync to gates. |
| `customer.updated` | Fetch full customer data from Cloud API, then idempotently sync to gates. |
| `customer.deleted` | Delete customer from gates without fetching full Cloud data. |

Example response:

```json
{
  "message": "Webhook accepted",
  "event": "customer.updated",
  "member_id": "M-1260-VJIV"
}
```

For created/updated events, the queued job calls:

```http
GET /api/customers/{member_id}
```

The Cloud API response may be either the customer object directly or wrapped in `data`.

### Manual Sync Customer To Gates

This endpoint is kept for local/manual testing when you already have the full customer payload.

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

### Idempotent Sync Behavior

Before writing customer data to a gate, the sync service searches the device first:

| Data | If found | If not found |
| --- | --- | --- |
| Person/UserInfo | `PUT /ISAPI/AccessControl/UserInfo/Modify` | `POST /ISAPI/AccessControl/UserInfo/Record` |
| CardInfo | `PUT /ISAPI/AccessControl/CardInfo/Modify` | `POST /ISAPI/AccessControl/CardInfo/Record` |
| FaceDataRecord | `PUT /ISAPI/Intelligent/FDLib/FDModify` | `POST /ISAPI/Intelligent/FDLib/FaceDataRecord` |

Face records use stable FPIDs so retake/replace can update existing face records instead of creating duplicates.

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

Face cleanup is intentionally not included in customer delete yet. Face records are keyed by FPID, so a safe delete/cleanup flow can be added later without using global face-library delete endpoints.

## Hikvision Endpoint Mapping

The service sends data to Hikvision through the `shaykhnazar/hikvision-isapi` package.

| Data | Hikvision ISAPI Endpoint |
| --- | --- |
| Customer/UserInfo | `POST /ISAPI/AccessControl/UserInfo/Record` |
| Customer/UserInfo update | `PUT /ISAPI/AccessControl/UserInfo/Modify` |
| Customer/UserInfo delete | `PUT /ISAPI/AccessControl/UserInfo/Delete` |
| CardInfo | `POST /ISAPI/AccessControl/CardInfo/Record` |
| CardInfo update | `PUT /ISAPI/AccessControl/CardInfo/Modify` |
| CardInfo delete | `PUT /ISAPI/AccessControl/CardInfo/Delete` |
| Face search | `POST /ISAPI/Intelligent/FDLib/FDSearch` |
| Face create | `POST /ISAPI/Intelligent/FDLib/FaceDataRecord` |
| Face update/retake | `PUT /ISAPI/Intelligent/FDLib/FDModify` |

Face data uses Hikvision FDLib `1` by default.

## Face Image Notes

For POS-based face enrollment, Eresto Cloud may send one or more Base64 face images.

Face records are stored with deterministic FPIDs:

| Input count | FPID |
| --- | --- |
| Single image | `{member_id}` |
| Multiple images | `{member_id}_1`, `{member_id}_2`, `{member_id}_3`, etc. |

If a face FPID already exists, this service uses `FDModify` to replace it. If it does not exist, this service uses `FaceDataRecord` to create it.

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
