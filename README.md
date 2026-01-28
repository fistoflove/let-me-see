# Let Me See 📸

A simple PHP service that renders HTML/CSS at different resolutions using headless Chrome and captures screenshots.

## 🎯 Features

- **HTML & URL endpoints** (`/render` and `/render-url`) for flexible capture flows
- **Multiple resolutions** - Capture mobile, tablet, desktop in one request
- **Headless Chrome** - High-quality browser rendering
- **⚡ Chrome Pool** - Persistent browser (60-80% faster after warm-up!)
- **Security first** - Script stripping, SSRF guards, path traversal protection, optional bearer token auth
- **Flexible output** - Full URLs or base64-encoded images
- **Precision timing** - Override timeouts or wait for animations before capture per request
- **Zero complexity** - No queues, no workers, just pure rendering

## 🚀 Quick Start

### Prerequisites

- PHP 8.0 or higher
- Composer
- Google Chrome or Chromium browser

### Installation

1. **Clone or navigate to the project directory:**
   ```bash
   cd /home/vini/projects/let-me-see
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Set up environment:**
   ```bash
   cp .env.example .env
   ```

4. **Edit `.env` file:**
   ```env
   BEARER_TOKEN=your-secret-token-here
   CHROME_PATH=/usr/bin/google-chrome
  STORAGE_PATH=./storage
  FILES_URL_PREFIX=/storage
  # FILES_BASE_URL=http://127.0.0.1:9601
   ```

5. **Find your Chrome path:**
   ```bash
   # Linux
   which google-chrome
   # or
   which chromium-browser
   
   # macOS
   # /Applications/Google Chrome.app/Contents/MacOS/Google Chrome
   ```

6. **Start the service (development):**
  ```bash
  php -S localhost:8080 app.php
  ```
   
  > **Tip:** This keeps everything in one process for quick local testing. For persistent Chrome and production use, follow the nginx + PHP-FPM setup below.

### PHAPI Runtime (Swoole)

For the PHAPI-based implementation (see `new-version/`), run the Swoole runtime directly:

```bash
APP_RUNTIME=swoole APP_PORT=9501 php new-version/public/index.php
```

- Set `APP_RUNTIME=portable_swoole` if you're using the portable runtime.
- Configure `APP_PORT`, `APP_HOST`, and `APP_DEBUG` as needed.
- Set `FILES_BASE_URL` in `.env` (for example, `http://127.0.0.1:9501`) so screenshot URLs include the correct host and port.

### Nginx + PHP-FPM Deployment

> These steps keep the PHP worker alive, so Chrome stays hot between requests.

1. **Install runtime (Ubuntu):**
  ```bash
  sudo apt install nginx php8.3-fpm
  ```

2. **Create nginx site config** at `/etc/nginx/sites-available/let-me-see`:
  ```nginx
  server {
     listen 8080;
     server_name localhost;

     root /home/vini/projects/let-me-see/public;
     index index.php;

     location /storage/ {
        try_files $uri $uri/ /index.php?$query_string;
     }

     location / {
        try_files $uri /index.php?$query_string;
     }

     location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_keep_conn on;
     }
  }
  ```

3. **Enable the site and reload nginx:**
  ```bash
  sudo ln -s /etc/nginx/sites-available/let-me-see /etc/nginx/sites-enabled/
  sudo nginx -t
  sudo systemctl reload nginx
  ```

4. **(Optional) Reduce FPM workers** to keep Chrome in one process:
  ```bash
  sudo sed -i 's/^pm.max_children = .*/pm.max_children = 1/' /etc/php/8.3/fpm/pool.d/www.conf
  sudo systemctl restart php8.3-fpm
  ```

5. **Test the service:**
  ```bash
  curl http://127.0.0.1:8080/status
  php benchmark.php
  ```
  You should see `chrome.alive: true` after the first request and warm render times well under one second.

### OpenSwoole Runtime (Docker)

If you prefer a single long-lived PHP worker, you can run the project inside the official OpenSwoole image. Chrome is not bundled in that container, so install it at runtime and point `CHROME_PATH` to the Chromium binary:

```bash
bin/run-swoole.sh
```

- `swoole_server.php` bridges the OpenSwoole HTTP server to the Slim app defined in `bootstrap.php`.
- The container publishes port `9601` on the host by default (mapped to port `9501` inside the container)—update `SWOOLE_PORT` if you prefer something else.
- Set `SWOOLE_WORKER_NUM` (>1) to allow multiple concurrent requests (each worker holds its own Chrome instance).
- Keep `STORAGE_PATH` relative (e.g. `./storage`) so the static handler can serve generated files from `/storage/...` immediately.
- Keep the container running while you benchmark; stopping it (CTRL+C) shuts everything down cleanly.
- Set `FILES_BASE_URL=http://127.0.0.1:9601` (or your public URL) in `.env` so generated screenshot links include the correct host and port.

Test the status endpoint once the server is running:

```bash
curl http://127.0.0.1:9601/status | jq
```

Stop the container when you're done:

```bash
bin/stop-swoole.sh
```

## 📡 API Usage

### POST /render

**Headers**

```
Content-Type: application/json
Authorization: Bearer your-secret-token-here
```

**Request Body**

```json
{
  "html": "<div style='padding: 20px;'><h1>Hello World</h1></div>",
  "css": "body { font-family: Arial; background: #f0f0f0; }",
  "resolutions": [
    { "width": 375, "height": 667, "label": "mobile" },
    { "width": 768, "height": 1024, "label": "tablet" },
    { "width": 1920, "height": 1080, "label": "desktop" }
  ],
  "returnBase64": false,
  "timeoutMs": 25000,
  "delayAfterLoadMs": 1500,
  "scrollSteps": 6,
  "scrollIntervalMs": 400,
  "optimizeForLlm": true
}
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `html` | string | Yes | HTML snippet rendered inside a sanitized template |
| `css` | string | No | Inline CSS injected into the `<head>` block |
| `resolutions` | array | Yes | Viewport list used for screenshots |
| `resolutions[].width` | int | Yes | Viewport width (1-7680) |
| `resolutions[].height` | int | Yes | Viewport height (1-4320) |
| `resolutions[].label` | string | Yes | Label for this screenshot |
| `returnBase64` | bool | No | Include base64-encoded images (default: false) |
| `timeoutMs` | int | No | Override navigation timeout in ms (1,000 – 120,000). Defaults to `RENDER_TIMEOUT`. |
| `delayAfterLoadMs` | int | No | Extra delay (ms) after navigation before screenshots (0 – 60,000). |
| `scrollSteps` | int | No | Number of viewport-height scrolls to trigger after load (0 – 20). |
| `scrollIntervalMs` | int | No | Delay between scroll steps in milliseconds (0 – 5,000). |
| `optimizeForLlm` | bool | No | When `true`, capture compressed JPEGs tuned for LLM ingestion (quality capped at 60 and max output 1280×720). |

**Response (Success)**

```json
{
  "success": true,
  "jobId": "2025-11-01_123456_a1b2c3d4e5f6g7h8",
  "screenshots": [
    { "label": "mobile", "width": 375, "height": 667, "url": "/files/2025-11-01_123456_a1b2c3d4e5f6g7h8/mobile.png" },
    { "label": "tablet", "width": 768, "height": 1024, "url": "/files/2025-11-01_123456_a1b2c3d4e5f6g7h8/tablet.png" },
    { "label": "desktop", "width": 1920, "height": 1080, "url": "/files/2025-11-01_123456_a1b2c3d4e5f6g7h8/desktop.png" }
  ]
}
```

> The HTML flow strips `<script>` tags before rendering.

**Response (Error)**

```json
{
  "success": false,
  "error": { "message": "Missing required field: html", "code": 400 }
}
```

### POST /render-url

**Headers**

```
Content-Type: application/json
Authorization: Bearer your-secret-token-here
```

**Request Body**

```json
{
  "url": "https://example.com/pricing",
  "resolutions": [
    { "width": 375, "height": 667, "label": "mobile" },
    { "width": 1280, "height": 720, "label": "desktop" }
  ],
  "returnBase64": true,
  "timeoutMs": 30000,
  "delayAfterLoadMs": 2000,
  "scrollSteps": 8,
  "scrollIntervalMs": 500,
  "optimizeForLlm": true
}
```

**Parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `url` | string | Yes | Public HTTP/HTTPS URL. Must resolve to a non-private IP. |
| `resolutions` | array | Yes | Viewport list used for screenshots |
| `resolutions[].width` | int | Yes | Viewport width (1-7680) |
| `resolutions[].height` | int | Yes | Viewport height (1-4320) |
| `resolutions[].label` | string | Yes | Label for this screenshot |
| `returnBase64` | bool | No | Include base64-encoded images (default: false) |
| `timeoutMs` | int | No | Override navigation timeout in ms (1,000 – 120,000). Defaults to `RENDER_TIMEOUT`. |
| `delayAfterLoadMs` | int | No | Extra delay (ms) after navigation before screenshots (0 – 60,000). |
| `scrollSteps` | int | No | Number of viewport-height scrolls to trigger after load (0 – 20). |
| `scrollIntervalMs` | int | No | Delay between scroll steps in milliseconds (0 – 5,000). |
| `optimizeForLlm` | bool | No | When `true`, capture compressed JPEGs tuned for LLM ingestion (quality capped at 60 and max output 1280×720). |

The response schema matches `POST /render`.

> The URL flow blocks private/reserved IP ranges and requires a reachable host.

## 🧪 Testing

### Using cURL

```bash
curl -X POST http://localhost:8080/render \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your-secret-token-here" \
  -d '{
    "html": "<div style=\"padding: 40px; text-align: center;\"><h1>Test Screenshot</h1><p>This is a test render</p></div>",
    "css": "body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-family: Arial, sans-serif; }",
    "resolutions": [
      {"width": 375, "height": 667, "label": "mobile"},
      {"width": 1920, "height": 1080, "label": "desktop"}
    ]
  }'
```

### Using PHP

```php
<?php

$url = 'http://localhost:8080/render';

$data = [
    'html' => '<div style="padding: 40px;"><h1>Hello from PHP!</h1></div>',
    'css' => 'body { background: #f0f0f0; font-family: Arial; }',
    'resolutions' => [
        ['width' => 375, 'height' => 667, 'label' => 'mobile'],
        ['width' => 1920, 'height' => 1080, 'label' => 'desktop']
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer your-secret-token-here'
]);

$response = curl_exec($ch);
$result = json_decode($response, true);

print_r($result);
```

### Using JavaScript/Fetch

```javascript
const response = await fetch('http://localhost:8080/render', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer your-secret-token-here'
  },
  body: JSON.stringify({
    html: '<div style="padding: 40px;"><h1>Hello from JS!</h1></div>',
    css: 'body { background: #f0f0f0; font-family: Arial; }',
    resolutions: [
      { width: 375, height: 667, label: 'mobile' },
      { width: 1920, height: 1080, label: 'desktop' }
    ]
  })
});

const result = await response.json();
console.log(result);
```

## 🔒 Security

### Authentication

Set a bearer token in `.env`:
```env
BEARER_TOKEN=your-super-secret-token-12345
```

Include it in requests:
```
Authorization: Bearer your-super-secret-token-12345
```

Leave `BEARER_TOKEN` empty to disable authentication (not recommended for production).

### Script Stripping

`POST /render` removes `<script>` tags before composing the page, preventing inline script execution. Chrome still runs first-party JavaScript for remote pages (and any assets the HTML references), so ship only trusted content.

### SSRF Guard

`POST /render-url` validates that the target uses HTTP/HTTPS and resolves to a public IP address. Private ranges (e.g. `10.0.0.0/8`, `192.168.0.0/16`, `fc00::/7`) and loopback hosts are rejected.

### Path Traversal Protection

The file server validates all paths to prevent directory traversal attacks.

## ⚡ Performance

### Chrome Pool Optimization

The service uses a **persistent Chrome instance** that stays running between requests:

- **First request:** ~2-5 seconds (cold start)
- **Subsequent requests:** ~0.5-2 seconds (**60-80% faster!**)

Chrome automatically closes after `CHROME_MAX_IDLE_TIME` seconds of inactivity to save memory.

### Status Monitoring

```bash
curl http://localhost:8080/status
```

Returns Chrome pool health information.

📖 **See [CHROME_POOL.md](CHROME_POOL.md) for detailed performance documentation**

## ⚙️ Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `BEARER_TOKEN` | - | API authentication token (leave empty to disable) |
| `CHROME_PATH` | `/usr/bin/google-chrome` | Path to Chrome/Chromium binary |
| `STORAGE_PATH` | `./storage` | Directory for storing screenshots |
| `FILES_URL_PREFIX` | `/storage` | URL prefix for accessing screenshots |
| `FILES_BASE_URL` | `-` | Optional absolute base URL for screenshot links (e.g., `http://127.0.0.1:9601`) |
| `MAX_HTML_SIZE` | `1048576` (1MB) | Maximum request body size in bytes |
| `MAX_RESOLUTIONS` | `10` | Maximum number of resolutions per request |
| `RENDER_TIMEOUT` | `30000` | Rendering timeout in milliseconds |
| `CHROME_MAX_IDLE_TIME` | `300` (5 min) | Seconds before Chrome auto-closes when idle |
| `BENCHMARK_URL` | `-` | Override benchmark target (defaults to service URL) |
| `FAST_RENDERER_DEBUG` | `false` | Enable render timing logs for diagnostics |
| `FAST_RENDERER_LOG` | `storage/logs/fast_renderer.log` | Path for renderer debug log |
| `SCREENSHOT_FORMAT` | `png` | `png` (default) or `jpeg` |
| `SCREENSHOT_QUALITY` | `80` | JPEG quality (10-100) when format is `jpeg` |
| `SCREENSHOT_FULL_PAGE` | `true` | Capture the entire document height instead of just the viewport |
| `URL_NAVIGATION_EVENT` | `networkIdle` | Chrome event to await before capturing URL renders (`domContentLoaded`, `load`, `networkIdle`, etc.) |
| `SWOOLE_WORKER_NUM` | `1` | Number of OpenSwoole workers (set >1 for parallel request handling) |
| `SWOOLE_DOCUMENT_ROOT` | project root | Override OpenSwoole static handler root (defaults to repository root) |

### Web Server Configuration

#### Apache

The included `.htaccess` file handles URL rewriting.

#### Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/let-me-see;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/files/ {
        try_files $uri /files.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## 🐳 Docker Deployment (Optional)

Create a `Dockerfile`:

```dockerfile
FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    wget \
    gnupg \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Install Chrome
RUN wget -q -O - https://dl-ssl.google.com/linux/linux_signing_key.pub | apt-key add - \
    && echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" >> /etc/apt/sources.list.d/google.list \
    && apt-get update \
    && apt-get install -y google-chrome-stable \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy application
COPY . /var/www/html/
WORKDIR /var/www/html

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage

EXPOSE 80
```

Build and run:
```bash
docker build -t let-me-see .
docker run -p 8080:80 -v $(pwd)/.env:/var/www/html/.env let-me-see
```

## 📊 Maintenance

### Cleanup Old Screenshots

Screenshots are stored indefinitely by default. To clean up old files:

```php
<?php
require_once 'vendor/autoload.php';

use LetMeSee\StorageManager;

$storage = new StorageManager('./storage');

// Delete jobs older than 24 hours
$deleted = $storage->cleanup(86400);
echo "Deleted {$deleted} old job directories\n";
```

### Cron Job for Automatic Cleanup

```bash
# Add to crontab (run daily at 3 AM)
0 3 * * * cd /path/to/let-me-see && php cleanup.php
```

## 🔧 Troubleshooting

### Chrome not found

**Error:** `Chrome communication error`

**Solution:** Verify Chrome path in `.env`:
```bash
which google-chrome
# or
which chromium-browser
```

### Permission denied on storage

**Error:** `mkdir(): Permission denied`

**Solution:** 
```bash
chmod 755 storage/
chown www-data:www-data storage/  # Linux
```

### 401 Unauthorized

**Error:** `Unauthorized`

**Solution:** Check your bearer token matches in `.env` and request headers.

## 📝 License

MIT License - feel free to use in your projects!

## 🤝 Contributing

Contributions welcome! Feel free to submit issues or pull requests.

---

**Built with ❤️ for quick and easy screenshot rendering**
