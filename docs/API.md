# REST API v1

## Base path

```text
/api/v1
```

JSON responses use `Content-Type: application/json`. Protected endpoints require:

```http
Authorization: Bearer <api-token>
```

API tokens are opaque. Only their hashes are stored in PostgreSQL.

## Scopes

| Scope | Purpose |
|---|---|
| `profile:read` | Read the authenticated identity |
| `courses:read` | Read course information through authenticated integrations and MCP |
| `library:read` | Read the learner’s course library |
| `learning:start` | Commence an assigned course |
| `courses:write` | Create and edit courses; platform-administrator role also required |
| `courses:publish` | Change publication state; platform-administrator role also required |
| `system:admin` | Scope wildcard; role checks still apply |

## Create an API token

```bash
cd /usr/local/lib/php/catto-learning/current
composer api-token:create -- \
  --email=administrator@example.com \
  --name="Local AI" \
  --scopes=profile:read,courses:read,library:read,learning:start,courses:write,courses:publish \
  --ttl-days=90
```

The raw token is displayed once. Store it securely.

List token metadata for an account:

```bash
composer api-token:list -- --email=administrator@example.com
```

Revoke it using the displayed token ID:

```bash
composer api-token:revoke -- \
  --email=administrator@example.com \
  --token-id=00000000-0000-0000-0000-000000000000
```

## Endpoints

### Public

```text
GET /api/v1
GET /api/v1/courses
GET /api/v1/courses/{slug}
```

### Authenticated learner

```text
GET  /api/v1/me                         profile:read
GET  /api/v1/library                    library:read
POST /api/v1/courses/{slug}/start       learning:start
```

Course commencement is permanent for company-credit purposes: it starts the access period and consumes the allocated credit.

### Platform administration

```text
POST /api/v1/admin/courses              courses:write + platform_admin
PUT  /api/v1/admin/courses/{id}         courses:write + platform_admin
POST /api/v1/admin/courses/{id}/status  courses:publish + platform_admin
```

## Error format

```json
{
  "error": {
    "status": 422,
    "code": "invalid_request",
    "message": "Description of the problem."
  }
}
```

This is the v1 foundation. Further product, company, request, cart, payment and reporting endpoints will be added with those features without bypassing the shared service layer.