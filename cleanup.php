<?php
/**
 * Cleanup script for old screenshots
 * Run this periodically to free up disk space
 */

require_once __DIR__ . '/vendor/autoload.php';

use LetMeSee\StorageManager;
use Dotenv\Dotenv;

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

$storagePath = $_ENV['STORAGE_PATH'] ?? __DIR__ . '/storage';
$storage = new StorageManager($storagePath);

// Delete jobs older than 24 hours (86400 seconds)
$olderThan = isset($argv[1]) ? (int)$argv[1] : 86400;

echo "Cleaning up jobs older than {$olderThan} seconds...\n";
$deleted = $storage->cleanup($olderThan);
echo "✓ Deleted {$deleted} old job directories\n";
