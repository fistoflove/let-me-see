# Let Me See → PHAPI conversion plan

## Goals
- Replace the Slim-based HTTP entry points with PHAPI while preserving the existing API behavior (`/status`, `/render`, `/render-url`, `/storage/*`, `/`).
- Keep existing rendering/storage logic intact (reuse current service classes in `src/`).
- Use the `new-version/` Composer setup (already includes `phapi/phapi`).

## Inventory of current behavior
- **Entry point:** `app.php` → `bootstrap.php` (Slim app).
- **Core services:** `RequestHandler`, `FastRenderer`, `StorageManager`, `HtmlComposer`, `ResponseBuilder` in `src/`.
- **Routes:**
  - `GET /status` — service and Chrome status.
  - `POST /render` — HTML/CSS payload to screenshots.
  - `POST /render-url` — URL payload to screenshots.
  - `GET /storage/{path}` — secure file serving.
  - `GET /` — returns `test.html`.
- **Cross-cutting:** CORS headers, optional bearer auth, `.env` config.

## Proposed PHAPI structure
```
new-version/
  app/
    Controllers/
      StatusController.php
      RenderController.php
      StorageController.php
      HomeController.php
    Middleware/
      CorsMiddleware.php
      AuthMiddleware.php
    Services/
      Config.php
      ResponseFactory.php
  routes/
    api.php
  config/
    app.php
  public/
    index.php
```

## Conversion steps
1. **Set up PHAPI entry point**
   - Create `new-version/public/index.php` to bootstrap PHAPI and load routes.
   - Add a minimal `config/app.php` with runtime/host/port/debug defaults (env-driven).

2. **Port configuration loading**
   - Build a small config helper (`app/Services/Config.php`) to load `.env` and map current env vars (`BEARER_TOKEN`, `CHROME_PATH`, etc.).
   - Ensure paths (storage, files URL prefix/base URL) resolve relative to repo root.

3. **Implement middleware**
   - **CORS:** replicate current headers in a PHAPI middleware.
   - **Auth:** replicate bearer token logic as middleware applied to `/render` and `/render-url`.

4. **Implement controllers/handlers**
   - **StatusController:** re-use `FastRenderer::getStatus()` and return JSON.
   - **RenderController:** call `RequestHandler`, `HtmlComposer`, `FastRenderer`, `StorageManager`, and `ResponseBuilder` exactly as today. Keep response shape and error handling.
   - **StorageController:** secure file resolution + streaming with content headers.
   - **HomeController:** return `test.html`.

5. **Route mapping**
   - Define routes in `routes/api.php` for all existing endpoints and attach middleware.
   - Confirm request parsing (JSON bodies, raw body) with PHAPI’s request object.

6. **Move or share the domain services**
   - Either:
     - Reuse `src/` directly by updating autoloading in `new-version/composer.json`, or
     - Move/copy `src/` into `new-version/app/Services` and update namespaces.
   - Preferred approach: update Composer autoload so existing namespaces stay intact and minimal changes are needed.

7. **Documentation & scripts**
   - Update README with PHAPI run instructions (`APP_RUNTIME=swoole` / `portable_swoole`).
   - Add a simple start command for local dev (if needed) and mention required Swoole runtime.

8. **Verification**
   - Run smoke tests for all endpoints using `curl` and confirm JSON payloads match.
   - Confirm storage file serving works and CORS/auth headers are correct.

## Risks & mitigations
- **PHAPI request API differences** → map request body/headers carefully; add local helpers for JSON parsing.
- **Runtime requirements** (Swoole) → provide a fallback “dev” command and document the requirement early.
- **Path resolution changes** → centralize path resolution in the config service and add unit checks.

## Deliverables
- New PHAPI app scaffold under `new-version/`.
- Route/controller/middleware implementations reproducing current behavior.
- Updated README and run notes for PHAPI.
- Optional: minimal tests or smoke scripts to validate conversion.
