# Railway deployment (Laravel + MySQL)

This project deploys as a Docker image. Railway builds the Vite files from
`resources`, installs the production PHP dependencies, serves Laravel's
`public` directory with Apache, and runs database migrations before starting
the web server. The source assets stay in `resources`; the generated files are
packaged as `public/build` in the image.

## 1. Create Railway services

1. Create a Railway project and add a **MySQL** service.
2. Add this repository as a separate application service. Keep the Dockerfile
   at the repository root so Railway detects it automatically.
3. Generate a Railway domain for the application service, or connect your own
   domain. Configure the service health-check path as `/up`.

The app must connect to MySQL using the private/internal host and port supplied
by Railway, not the public proxy address.

## 2. Configure application variables

In the application service's **Variables** settings, add:

| Variable | Value |
| --- | --- |
| `APP_NAME` | `VaxTrack` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | A newly generated Laravel key; see below |
| `APP_URL` | The full HTTPS URL for the Railway domain |
| `LOG_CHANNEL` | `stderr` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | Reference the MySQL service's `MYSQLHOST` |
| `DB_PORT` | Reference the MySQL service's `MYSQLPORT` |
| `DB_DATABASE` | Reference the MySQL service's `MYSQLDATABASE` |
| `DB_USERNAME` | Reference the MySQL service's `MYSQLUSER` |
| `DB_PASSWORD` | Reference the MySQL service's `MYSQLPASSWORD` |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` |

Use Railway's **reference variable** picker for the five MySQL values. If the
database service is named `MySQL`, the references are
`${{MySQL.MYSQLHOST}}`, `${{MySQL.MYSQLPORT}}`,
`${{MySQL.MYSQLDATABASE}}`, `${{MySQL.MYSQLUSER}}`, and
`${{MySQL.MYSQLPASSWORD}}`. Adjust the service name if yours differs. Keep
these values in Railway; do not commit credentials or production `.env` files.

Generate an application key from the project directory with:

```sh
php artisan key:generate --show
```

Copy the printed `base64:...` value into Railway's `APP_KEY` variable. Generate
a fresh key for production and keep it stable after deployment; changing it
invalidates encrypted data and active sessions.

Do not set `PORT` yourself. Railway provides it, and the container configures
Apache to listen on that port. The image defaults to production mode and
requires `APP_KEY` and the MySQL connection variables before it starts.

## 3. Deploy and initialize

Deploy the application service. On startup, it runs `php artisan migrate --force`
and `php artisan optimize`; a migration or configuration error stops the
container and is reported in the Railway deployment logs. The `/up` health
endpoint is available for Railway's health check.

After the first successful deployment, initialize the reference data and the
first CHO administrator once:

1. Add `CHO_ADMIN_PASSWORD` as a Railway secret variable. Use a unique password
   of at least 12 characters.
2. Open a shell for the running application service and run:

   ```sh
   php artisan db:seed --force
   ```

3. Remove `CHO_ADMIN_PASSWORD` from the service variables after the seeder
   succeeds. The seeded CHO administrator must change this password at first
   login.

Do not run `db:seed` on every deploy: the CHO seeder updates the administrator
account and will reset its password and require a password change again.
Subsequent deployments only need the automatically run migrations.

## Data and operations

- Production records, sessions, cache, and queued jobs use Railway MySQL. The
  app container's filesystem is ephemeral; do not store persistent user files
  there. The current CSV import is processed from its temporary upload and
  saved to MySQL, and the bundled forecast dataset is included in the image.
- A new Railway MySQL database starts empty. Existing local SQLite data is not
  copied by this deployment; plan and verify a separate data migration before
  switching users to production.
- Keep Railway's MySQL backups enabled and test restoring them. Never rotate
  `APP_KEY` casually.
- Keep `APP_DEBUG=false` in production and use Railway logs to diagnose errors.