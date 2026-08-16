<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$statusFile = '/tmp/dash_hotlin_update.status';
$logFile = '/var/log/update_dash_html.log';

$percent = 0;
$message = 'En attente du démarrage...';
$success = false;

if (is_readable($statusFile)) {
    $status = trim((string) file_get_contents($statusFile));
    $parts = explode('|', $status, 2);

    if (isset($parts[0]) && is_numeric($parts[0])) {
        $percent = max(0, min(100, (int) $parts[0]));
    }

    if (isset($parts[1])) {
        $message = trim($parts[1]);
    }
}

$log = '';

if (is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES);

    if (is_array($lines)) {
        $log = implode("\n", array_slice($lines, -18));
    }
}

if ($percent === 100 && stripos($message, 'succès') !== false) {
    $success = true;
}

echo json_encode([
    'percent' => $percent,
    'message' => $message,
    'success' => $success,
    'log' => $log
], JSON_UNESCAPED_UNICODE);