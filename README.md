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
  -> CustomerGateSyncDispatcher (initialize Redis status)
  -> SyncCustomerToGatesJob coordinator
  -> SyncCustomerToGateJob per device (fan-out)
  -> Queue worker / Horizon
  -> CloudCustomerClient GET /api/customers/{member_id}
  -> CustomerGatePayloadBuilder
  -> CustomerGateSyncService
  -> One Hikvision gate device per job
```

## Project Structure

```text
routes/web.php
  Local server webhook and sync-status route definitions.

app/Console/Commands/SyncCustomerDeltaCommand.php
  Fetches incremental Cloud changes, queues sync jobs, and advances the Redis cursor.

app/Http/Controllers/CloudWebhookController.php
  Receives cloud customer events, validates optional HMAC signature, and queues sync jobs.

app/Http/Controllers/Api/HikvisionSyncStatusController.php
  Returns the latest per-device customer sync status.

app/DTOs/CloudCustomerData.php
  Validates and normalizes the Cloud customer contract before queue fan-out.

app/DTOs/CloudCustomerDeltaData.php
  Validates the Cloud delta response, change events, timestamps, and next cursor.

app/Jobs/SyncCustomerToGatesJob.php
  Coordinator job that fetches customer data once and fans out one child job per device.

app/Jobs/SyncCustomerToGateJob.php
  Syncs one customer to one gate with independent retry and backoff.

app/Services/Cloud/CloudCustomerClient.php
  Fetches customer records from Eresto Cloud API.

app/Services/Cloud/CustomerDeltaSyncStateStore.php
  Stores the delta cursor and distributed lock in Redis.

app/Services/Hikvision/CustomerGatePayloadBuilder.php
  Builds Hikvision Person, Card, and face image payload data.

app/Services/Hikvision/CustomerGateSyncService.php
  Performs idempotent writes to a specific Hikvision gate.

app/Services/Hikvision/CustomerGateSyncDispatcher.php
  Initializes status and queues the coordinator with a fixed device list.

app/Services/Hikvision/CustomerGateSyncStatusStore.php
  Stores aggregate and per-device sync status with a configurable TTL.

config/hikvision.php
  Hikvision device configuration.

tests/Feature/HikvisionSyncCustomerTest.php
  Mocked feature tests for dispatcher, per-device jobs, status, card, and face sync behavior.
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
ERESTO_DELTA_SYNC_ENABLED=false
ERESTO_DELTA_SYNC_STORE=redis
ERESTO_DELTA_SYNC_CURSOR_KEY=eresto:customer-delta:last-cursor
ERESTO_DELTA_SYNC_LOCK_KEY=eresto:customer-delta:lock
ERESTO_DELTA_SYNC_LOCK_SECONDS=1800
```

If `ERESTO_CLOUD_WEBHOOK_SECRET` is set, incoming `/cloud/webhook` requests must include one of these headers:

```text
X-Hub-Signature: sha256=<hmac>
X-Hub-Signature-256: sha256=<hmac>
```

The HMAC is calculated from the raw request body using SHA-256 and the shared secret.

## Queue Configuration

The `/cloud/webhook` endpoint is asynchronous. It validates the request, queues a coordinator job, and returns `202 Accepted`. The coordinator fetches Cloud data once when needed, then queues one `SyncCustomerToGateJob` per configured device. A failed gate can therefore retry without repeating successful gate work.

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
HIKVISION_SYNC_STATUS_STORE=redis
HIKVISION_SYNC_STATUS_TTL=604800
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
  "member_id": "M-1260-VJIV",
  "devices": ["xgym_entrance", "xgym_exit"]
}
```

For created/updated events, the queued job calls:

```http
GET /api/customers/{member_id}
```

The Cloud API response may be either the customer object directly or wrapped in `data`.

Before fan-out, the response is converted into the local `CloudCustomerData` DTO. The DTO accepts only the fields used by the gate flow; additional Cloud fields are ignored.

| Field | Rule | Default |
| --- | --- | --- |
| `member_id` | Required string; must match the requested member ID. | none |
| `name` | Required string. | none |
| `start_date` | Required valid date. | none |
| `end_date` | Required valid date on or after `start_date`. | none |
| `status` | `active` or `inactive`. | `active` |
| `card_no` | Optional string. | `member_id` when empty |
| `face_images_base64` | Optional array of non-empty strings. | `[]` |

An invalid Cloud response throws a validation exception. The coordinator job is retried according to its queue policy and is marked failed when all attempts are exhausted.

### Periodic Delta Sync

Delta sync is the fallback when a webhook is delayed or missed. Run it manually with:

```bash
php artisan sync:delta
```

The command reads the last cursor from Redis and requests:

```http
GET /api/customers/delta?since={cursor}
```

Expected Cloud response:

```json
{
  "data": [
    {
      "member_id": "M-123",
      "event": "customer.updated",
      "modified_at": "2026-07-10T10:00:00Z"
    },
    {
      "member_id": "M-456",
      "event": "customer.deleted",
      "modified_at": "2026-07-10T10:01:00Z"
    }
  ],
  "next_cursor": "cursor-2026-07-10T10:01:00Z"
}
```

The Cloud endpoint must return changes in ascending change-log order and at most 500 records per response. If one customer appears multiple times in the same response, local sync keeps only that customer's final event before dispatch. Each resulting change is sent through `CustomerGateSyncDispatcher`, so webhook and delta sync share the same per-gate jobs, idempotency, retry, and status flow. Deleted customers are queued with `customer.deleted` and are not fetched again from the Cloud customer endpoint.

The cursor advances only after every change has been successfully queued. This treats the durable Redis queue as the handoff boundary: permanently failed jobs must be monitored and retried through Horizon or a later reconciliation process. If the Cloud request, validation, or dispatch fails before enqueue completes, the previous cursor is preserved and the changes are retried on the next execution. A 30-minute Redis lock prevents overlapping command executions.

To ignore the saved cursor and request a full delta:

```bash
php artisan sync:delta --reset
```

Laravel schedules the command every five minutes. Delta sync is disabled by default and should only be enabled with `ERESTO_DELTA_SYNC_ENABLED=true` after the Cloud endpoint is deployed. For local Docker testing, run the scheduler in a separate terminal:

```bash
docker compose exec laravel.test php artisan schedule:work
```

On a non-Docker server, add the standard Laravel scheduler cron entry:

```cron
* * * * * cd /var/www/eresto-local-gate && php artisan schedule:run >> /dev/null 2>&1
```

### Check Customer Sync Status

The dispatcher records one status per gate. Status data uses the configured cache store (Redis by default) and expires after `HIKVISION_SYNC_STATUS_TTL` seconds.

```http
GET /admin/status/{member_id}
Accept: application/json
```

Example response:

```json
{
  "data": {
    "member_id": "M-1260-VJIV",
    "event": "customer.updated",
    "status": "success",
    "devices": {
      "xgym_entrance": {
        "device": "xgym_entrance",
        "status": "success",
        "attempt": 1
      },
      "xgym_exit": {
        "device": "xgym_exit",
        "status": "success",
        "attempt": 1
      }
    }
  }
}
```

Per-device states are `pending`, `processing`, `retrying`, `success`, or `failed`. The endpoint returns `404` when no status exists or its TTL has expired.

### Idempotent Sync Behavior

Before writing customer data to a gate, the sync service searches the device first:

| Data | If found | If not found |
| --- | --- | --- |
| Person/UserInfo | `PUT /ISAPI/AccessControl/UserInfo/Modify` | `POST /ISAPI/AccessControl/UserInfo/Record` |
| CardInfo | `PUT /ISAPI/AccessControl/CardInfo/Modify` | `POST /ISAPI/AccessControl/CardInfo/Record` |
| FaceDataRecord | `PUT /ISAPI/Intelligent/FDLib/FDModify` | `POST /ISAPI/Intelligent/FDLib/FaceDataRecord` |

Face records use stable FPIDs so retake/replace can update existing face records instead of creating duplicates.

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

Run the delta command tests:

```bash
docker compose exec -T laravel.test php artisan test tests/Feature/SyncCustomerDeltaCommandTest.php
```

The test uses a fake Hikvision HTTP client, so it does not require a real gate device.

## Development Notes

- Do not edit files inside `vendor/` for project behavior.
- Keep controller logic thin. Queue heavy sync work through jobs.
- Put Hikvision payload building in `CustomerGatePayloadBuilder`.
- Put device communication logic in `CustomerGateSyncService`.
- Add tests when changing endpoint payload, card sync, or face sync behavior.
