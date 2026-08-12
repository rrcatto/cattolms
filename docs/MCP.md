# MCP server

## Design

Catto Learning includes a core MCP server. It is not a database shell. Every tool authenticates an LMS API token and calls the same application services and permission rules used by the website and REST API.

Version 0.5.4 uses the official PHP MCP SDK (`mcp/sdk` 0.7 series) and local STDIO transport. Remote Streamable HTTP transport and OAuth are deferred until remote MCP access is required.

## Create a token

Create a token for the user whose permissions the AI must exercise:

```bash
cd /usr/local/lib/php/catto-learning/current
composer api-token:create -- \
  --email=administrator@example.com \
  --name="Catto Learning MCP" \
  --scopes=profile:read,courses:read,library:read,learning:start,courses:write,courses:publish \
  --ttl-days=90
```

The account must also hold `platform_admin` for administrative tools.

## Launch

```bash
CATTO_LEARNING_API_TOKEN='clpat_...' \
php /usr/local/lib/php/catto-learning/current/tools/mcp-server.php
```

The normal deployment `.env` is located automatically through `/usr/local/lib/php/catto-learning/.env`. For an unusual layout, set `CATTO_LEARNING_ENV_FILE` to the real `.env` path.

## MCP client configuration

A typical local configuration is:

```json
{
  "mcpServers": {
    "catto-learning": {
      "command": "php",
      "args": [
        "/usr/local/lib/php/catto-learning/current/tools/mcp-server.php"
      ],
      "env": {
        "CATTO_LEARNING_API_TOKEN": "clpat_replace-with-token"
      }
    }
  }
}
```

Do not commit the token to source control.

## Initial tools

| Tool | Required authority |
|---|---|
| `lms_status` | valid token |
| `list_courses` | `courses:read` |
| `get_course` | `courses:read` |
| `list_my_library` | `library:read` |
| `start_course` | `learning:start` |
| `create_course` | `courses:write` and `platform_admin` |
| `set_course_status` | `courses:publish` and `platform_admin` |

MCP write operations are attributed to the authenticated user and token in the audit log.