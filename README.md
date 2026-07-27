# Slim PHP API

Built with Slim 4.15 and PHP 8.2 or newer.

## Features

- Public API home endpoint
- Login and registration request validation
- JWT authentication with protected routes
- Authenticated user dashboard
- Profile updates with validation
- Paginated user listing, user lookup, and user creation
- MySQL persistence through Eloquent ORM
- Password hashing and user status scopes
- JSON error handling, logging, CORS, and no-cache headers

## Setup

Create `.env` from `.env.example`, install the Composer dependencies, and import
`database.sql` into MySQL. Replace `JWT_SECRET` with a cryptographically random
secret of at least 32 bytes before issuing tokens.

## Routes

- POST: /api/auth/login
- POST: /api/auth/register

Protected routes (Bearer token required):

- GET: /api/dashboard
- POST: /api/profile
- GET: /api/users
- POST: /api/users
- GET: /api/users/{id}
