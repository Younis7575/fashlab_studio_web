<?php
/**
 * Secure image upload handling.
 * - Validates MIME type via finfo, extension, size and dimensions.
 * - Generates a safe random filename — user filenames are never trusted.
 * - Stores files on disk (never in the database); only the path is saved.
 *
 * Returns: ['success' => bool, 'path' => string, 'error' => string]
 */

declare(strict_types=1);

const ALLOWED_IMAGE_TYPES = [
    'image/jpeg' => ['jpg', 'jpeg'],
    'image/png'  => ['png'],
    'image/webp' => ['webp'],
    'image/gif'  => ['gif'],
];

const MIN_IMAGE_DIMENSION = 200;

function upload_image(array $file, string $subdir): array
{
    $result = ['success' => false, 'path' => '', 'error' => ''];

    if (!isset($file['error']) || is_array($file['error'])) {
        $result['error'] = 'Invalid upload.';
        return $result;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['error'] = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image is too large (max ' . round(UPLOAD_MAX_SIZE / 1048576) . ' MB).',
            UPLOAD_ERR_NO_FILE => 'No file was selected.',
            default => 'Upload failed. Please try again.',
        };
        return $result;
    }

    if ($file['size'] > UPLOAD_MAX_SIZE) {
        $result['error'] = 'Image is too large (max ' . round(UPLOAD_MAX_SIZE / 1048576) . ' MB).';
        return $result;
    }

    // Real MIME type — never trust the client header.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($file['tmp_name']);

    if (!isset(ALLOWED_IMAGE_TYPES[$mime])) {
        $result['error'] = 'Only JPG, PNG, WebP or GIF images are allowed.';
        return $result;
    }

    // Dimensions (also rejects corrupt files).
    $dimensions = @getimagesize($file['tmp_name']);
    if ($dimensions === false) {
        $result['error'] = 'The uploaded file is not a valid image.';
        return $result;
    }
    if ($dimensions[0] < MIN_IMAGE_DIMENSION || $dimensions[1] < MIN_IMAGE_DIMENSION) {
        $result['error'] = 'Image is too small (minimum ' . MIN_IMAGE_DIMENSION . 'x' . MIN_IMAGE_DIMENSION . 'px).';
        return $result;
    }

    $ext      = ALLOWED_IMAGE_TYPES[$mime][0];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    $baseDir = __DIR__ . '/../uploads/';
    $targetDir = $baseDir . $subdir;
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            $result['error'] = 'Upload directory is not writable.';
            return $result;
        }
    }

    $relative = 'uploads/' . $subdir . '/' . $filename;
    $target   = $baseDir . $subdir . '/' . $filename;

    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        $result['error'] = 'Could not save the uploaded image.';
        return $result;
    }

    @chmod($target, 0644);

    /* Shrink big photos (phone/AI exports are often 2–8 MB) so pages load fast. */
    $optimized = tc_optimize_image_file($target);
    if ($optimized !== null) {
        $relative = 'uploads/' . $subdir . '/' . basename($optimized);
    }

    $result['success'] = true;
    $result['path']    = $relative;
    return $result;
}

const TC_IMAGE_MAX_DIMENSION = 1400;

/** True when the server can resize and re-encode images. */
function tc_image_optimizer_available(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagecreatefromjpeg')
        && function_exists('imagecreatefrompng') && (tc_webp_supported() || function_exists('imagejpeg'));
}

function tc_webp_supported(): bool
{
    return function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
}

/** Sample a grid of pixels; true when any of them is not fully opaque. */
function tc_gd_has_transparency(GdImage $img, int $w, int $h): bool
{
    $steps = 60;
    for ($i = 0; $i <= $steps; $i++) {
        for ($j = 0; $j <= $steps; $j++) {
            $x = (int) min($w - 1, round($i * ($w - 1) / $steps));
            $y = (int) min($h - 1, round($j * ($h - 1) / $steps));
            if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                return true;
            }
        }
    }
    return false;
}

/** PNG colour type 4/6 (or a tRNS chunk) means the image may be transparent. */
function tc_png_has_alpha(string $absPath): bool
{
    $head = (string) @file_get_contents($absPath, false, null, 0, 64);
    if (strlen($head) < 26) {
        return true;
    }
    $colorType = ord($head[25]);
    return $colorType === 4 || $colorType === 6 || str_contains($head, 'tRNS');
}

/**
 * Resize an image to TC_IMAGE_MAX_DIMENSION and re-encode it as WebP next to
 * the original. Returns the new absolute path, or null when it was skipped
 * (GIF, unsupported server, or the result would not be smaller).
 * When $deleteOriginal is true the source file is removed after success.
 */
function tc_optimize_image_file(string $absPath, bool $deleteOriginal = true): ?string
{
    if (!tc_image_optimizer_available() || !is_file($absPath)) {
        return null;
    }
    $info = @getimagesize($absPath);
    if ($info === false) {
        return null;
    }
    [$w, $h] = $info;
    $mime = (string) ($info['mime'] ?? '');
    $originalSize = (int) filesize($absPath);

    // Already small WebP within bounds — nothing to gain.
    if ($mime === 'image/webp' && max($w, $h) <= TC_IMAGE_MAX_DIMENSION && $originalSize < 400 * 1024) {
        return null;
    }
    // Guard memory on shared hosting (~4 bytes per pixel, twice).
    if ($w * $h > 40_000_000) {
        return null;
    }

    try {
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($absPath),
            'image/png'  => @imagecreatefrompng($absPath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absPath) : false,
            default      => false,
        };
        if (!$src) {
            return null;
        }
        // Without WebP the output is JPEG: keep genuinely transparent PNGs (logos, cut-outs) as they are.
        if ($mime === 'image/png' && !tc_webp_supported() && tc_png_has_alpha($absPath) && tc_gd_has_transparency($src, $w, $h)) {
            return null;
        }

        $scale = min(1, TC_IMAGE_MAX_DIMENSION / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $useWebp = tc_webp_supported();
        $ext = $useWebp ? 'webp' : 'jpg';
        $newPath = preg_replace('/\.[a-z0-9]+$/i', '', $absPath) . '.' . $ext;
        if ($newPath === $absPath) {
            $newPath = preg_replace('/\.[a-z0-9]+$/i', '', $absPath) . '-opt.' . $ext;
        }
        if ($useWebp) {
            $ok = imagewebp($dst, $newPath, 82);
        } else {
            // JPEG has no transparency: flatten onto white.
            $flat = imagecreatetruecolor($nw, $nh);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $dst, 0, 0, 0, 0, $nw, $nh);
            imageinterlace($flat, true);
            $ok = imagejpeg($flat, $newPath, 82);
        }

        if (!$ok || !is_file($newPath) || filesize($newPath) >= $originalSize) {
            if (is_file($newPath) && $newPath !== $absPath) {
                @unlink($newPath);
            }
            return null;
        }
        @chmod($newPath, 0644);
        if ($deleteOriginal && $newPath !== $absPath) {
            @unlink($absPath);
        }
        return $newPath;
    } catch (Throwable $e) {
        error_log('[image-optimize] ' . $e->getMessage());
        return null;
    }
}

/** Delete an image file if it lives inside uploads/. Returns void. */
function delete_uploaded_file(string $relativePath): void
{
    $relativePath = ltrim($relativePath, '/');
    if (!str_starts_with($relativePath, 'uploads/')) {
        return;
    }
    $full = __DIR__ . '/../' . $relativePath;
    $base = realpath(__DIR__ . '/../uploads/');
    $fullReal = realpath($full);
    if ($fullReal !== false && $base !== false && str_starts_with($fullReal, $base)) {
        @unlink($fullReal);
    }
}