<?php
/**
 * FightxLife CONTENT BUILD — media upload (2026-10-28, on Fahim's word).
 * Scans /tmp/fxl-images/ (docker cp'd from the host), uploads every file
 * to Studio media under uploads/media/2026/10/ with ASCII-slug names,
 * registers each, prints MEDIA-OK lines + a manifest JSON.
 * SKIPS the 2 footer pictures (the partials script uploads those itself,
 * so no duplicate rows). The white footer logo is NOT in this dir — the
 * partials script uploads it from /tmp/fxl-logo-white.jpg.
 * No page changes here; page scripts consume the printed manifest.
 */
declare(strict_types=1);

require '/var/www/html/config.php';
$GLOBALS['SLATE_TENANT_OVERRIDE'] = 1;

function fail(string $msg): void
{
    fwrite(STDERR, "MEDIA-FAIL: $msg\n");
    exit(1);
}

function slugify(string $name): string
{
    $map = ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'á' => 'a', 'â' => 'a',
        'ç' => 'c', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u',
        'ü' => 'u', 'ÿ' => 'y', 'æ' => 'ae', 'œ' => 'oe', 'É' => 'e', 'È' => 'e', 'À' => 'a',
        'Ç' => 'c', 'Î' => 'i', 'Ô' => 'o', 'Ù' => 'u', 'Ä' => 'a', 'Ö' => 'o', 'Ü' => 'u'];
    $base = strtr($name, $map);
    $base = mb_strtolower($base, 'UTF-8');
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    return trim($base, '-');
}

$scanDir = '/tmp/fxl-images';
$skip = ['female-boxers-hit-big-punching-bag-boxing-stadium.jpg', 'boxer-training-hitting-heavy-bag.jpg'];
if (!is_dir($scanDir)) {
    fail("scan dir $scanDir missing");
}
$files = [];
foreach (scandir($scanDir) as $f) {
    if ($f === '.' || $f === '..') {
        continue;
    }
    if (in_array($f, $skip, true)) {
        echo "MEDIA-SKIP $f (uploaded by partials script)\n";
        continue;
    }
    $full = $scanDir . '/' . $f;
    if (is_file($full)) {
        $files[] = $f;
    }
}
sort($files);
if (count($files) === 0) {
    fail('no files to upload');
}
$mediaDir = '/var/www/html/uploads/media/2026/10';
if (!is_dir($mediaDir)) {
    mkdir($mediaDir, 0755, true);
}
$manifest = [];
foreach ($files as $orig) {
    $src = $scanDir . '/' . $orig;
    $bytes = file_get_contents($src);
    if ($bytes === false) {
        fail("cannot read $src");
    }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }
    if (!in_array($ext, ['jpg', 'png'], true)) {
        fail("unsupported extension for $orig");
    }
    $stem = slugify(pathinfo($orig, PATHINFO_FILENAME));
    $final = $stem . '.' . $ext;
    $i = 0;
    while (file_exists($mediaDir . '/' . $final)) {
        $i++;
        $final = $stem . '-' . $i . '.' . $ext;
    }
    $full = $mediaDir . '/' . $final;
    if (file_put_contents($full, $bytes) === false) {
        fail("cannot write $full");
    }
    chmod($full, 0644);
    @chown($full, 'www-data');
    @chgrp($full, 'www-data');
    $meta = ['mime' => $ext === 'png' ? 'image/png' : 'image/jpeg', 'size_bytes' => strlen($bytes), 'original_name' => $orig];
    $size = @getimagesize($full);
    if (is_array($size)) {
        $meta['width'] = $size[0];
        $meta['height'] = $size[1];
    }
    $rel = '/uploads/media/2026/10/' . $final;
    $id = \Slate\Services\Media\Media::register($rel, $meta);
    $manifest[] = ['slug' => $stem, 'file' => $final, 'orig' => $orig, 'id' => $id, 'url' => $rel];
    echo 'MEDIA-OK ' . $final . ' | ' . $orig . ' | id=' . $id . ' | ' . $rel . "\n";
}
file_put_contents('/tmp/fxl-media-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo 'MEDIA-DONE count=' . count($manifest) . "\n";
