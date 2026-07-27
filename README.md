# Slim PHP API

Built with Slim 4.15 and PHP 8.2 or newer.

## Features

- Public API home endpoint
- Login and registration request validation
- JWT authentication with protected routes
- Rotating refresh tokens and server-side token revocation
- Password reset and email verification by SMTP
- Immutable, redacted audit logging with request IDs
- Authenticated user dashboard
- User profile, password, and account self-service
- Role-protected administrator API
- Paginated user search, filtering, sorting, and management
- User status management and soft deletion
- Typed public/admin software settings
- Private file, document, and avatar uploads
- MySQL persistence through Eloquent ORM
- Password hashing and user status scopes
- JSON error handling, logging, CORS, and no-cache headers

## Setup

Create `.env` from `.env.example`, install the Composer dependencies, and import
`database.sql` into MySQL. For an existing database, run `database-update.sql`
once instead. Replace `JWT_SECRET` with a cryptographically random secret of at
least 32 bytes before issuing tokens.

Configure `MAILER_DSN`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, and `APP_URL` to
deliver password-reset and verification emails. When `APP_ENV=local`, the API
also returns one-time verification/reset tokens to make local API testing
possible. Tokens are never returned this way in production.

## Routes

- POST: /api/auth/login
- POST: /api/auth/register
- POST: /api/auth/refresh
- POST: /api/auth/logout (Bearer token required)
- POST: /api/auth/logout-all (Bearer token required)
- POST: /api/auth/password/forgot
- POST: /api/auth/password/reset
- POST: /api/auth/email/verify
- POST: /api/auth/email/resend
- GET: /api/settings

Protected routes (Bearer token required):

- GET: /api/dashboard
- GET: /api/me
- PATCH: /api/me
- PATCH: /api/me/password
- POST: /api/me/avatar
- DELETE: /api/me
- GET: /api/files
- POST: /api/files
- GET: /api/files/{id}
- GET: /api/files/{id}/download
- DELETE: /api/files/{id}

Administrator routes (Bearer token for an active admin required):

- GET: /api/admin/system/info
- GET: /api/admin/audit-logs
- GET: /api/admin/audit-logs/{id}
- GET: /api/admin/settings
- GET: /api/admin/settings/{key}
- PUT: /api/admin/settings/{key}
- DELETE: /api/admin/settings/{key}
- GET: /api/admin/files
- GET: /api/admin/files/{id}
- GET: /api/admin/files/{id}/download
- DELETE: /api/admin/files/{id}
- GET: /api/admin/users/stats
- GET: /api/admin/users
- POST: /api/admin/users
- GET: /api/admin/users/{id}
- PATCH: /api/admin/users/{id}
- PATCH: /api/admin/users/{id}/status
- DELETE: /api/admin/users/{id}

The user list supports `page`, `per_page`, `search`, `role`, `status`, `sort`,
and `direction` query parameters. Public registration always assigns the `user`
role. Create the first administrator directly in the database, then manage roles
through the administrator API.

File uploads use `multipart/form-data` with a `file` field and an optional
`category` of `avatar`, `document`, or `attachment`. Uploaded content is private
and requires an owner or administrator Bearer token to access. Default allowed
types and size limits are configured with `UPLOAD_ALLOWED_MIME_TYPES` and
`UPLOAD_MAX_SIZE_MB`; server-side MIME detection is always enforced.

Settings support `string`, `integer`, `boolean`, and `json` types. Only settings
explicitly marked `is_public: true` are returned by `GET /api/settings`.

Access tokens expire after 15 minutes by default. Refresh tokens expire after
30 days and rotate whenever they are used. Store refresh tokens only in secure,
HTTP-only cookies or equivalent protected client storage. Reusing an already
rotated refresh token revokes that token family and all active sessions.

Audit log listing supports `actor_id`, `action`, `entity_type`, `entity_id`,
`from`, `to`, `search`, `page`, and `per_page`. Audit records capture the actor,
request ID, IP address, user agent, entity, and redacted before/after values.
Clients may send an `X-Request-Id` header; otherwise the API generates one and
returns it in every response.

## Adding a CRUD resource

For a standard Eloquent resource, extend `CrudController` and define only:

- `modelClass()`
- `createRules()` and `updateRules()`
- `writableFields()`
- Optional search, filter, sort, transform, and lifecycle hooks

The base controller supplies paginated listing, search, exact filters, sorting,
show, create, update, delete, consistent validation/responses, safe field
assignment, and automatic create/update/delete audit records. Copy
`src/Application/Controllers/skel.skel` when starting a new controller, then map
its `index`, `store`, `show`, `update`, and `destroy` methods in `app/routes.php`.

The older `POST /api/profile` and administrator-only `/api/users` read/create
routes remain available for backward compatibility.
