<?php

namespace App\Service;

class PdfAssetPathResolver
{
    public function __construct(private string $projectDir)
    {
    }

    public function resolvePublicPath(?string $relativePath): ?string
    {
        if (!$relativePath) {
            return null;
        }

        $relativePath = trim($relativePath);


        if (str_starts_with($relativePath, 'data:image/')) {
            return $this->materializeDataUri($relativePath) ?? $relativePath;
        }

        if (str_starts_with($relativePath, 'file://') || str_starts_with($relativePath, 'http://') || str_starts_with($relativePath, 'https://')) {
            return $relativePath;
        }

        $cleanPath = trim(parse_url($relativePath, PHP_URL_PATH) ?? '');
        if ($cleanPath === '') {
            return null;
        }

        $cleanPath = ltrim($cleanPath, '/');
        $fullPath = $this->projectDir.'/public/'.$cleanPath;
        $realPath = realpath($fullPath);

        if ($realPath === false || !is_file($realPath)) {
            return null;
        }

        return 'file://'.$this->encodeFilePath($realPath);
    }



    private function materializeDataUri(string $dataUri): ?string
    {
        if (preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUri, $matches) !== 1) {
            return null;
        }

        $mimeType = strtolower($matches[1]);
        $binary = base64_decode($matches[2], true);

        if ($binary === false) {
            return null;
        }

        if ($mimeType === 'image/webp' && function_exists('imagecreatefromstring')) {
            $image = @imagecreatefromstring($binary);
            if ($image !== false) {
                ob_start();
                imagepng($image);
                $binary = (string) ob_get_clean();
                imagedestroy($image);
                $mimeType = 'image/png';
            }
        }

        $extension = match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => 'img',
        };

        $dir = $this->projectDir.'/var/exports/pdf-assets';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $filename = 'asset-'.sha1($dataUri).'.'.$extension;
        $path = $dir.'/'.$filename;

        if (!is_file($path)) {
            file_put_contents($path, $binary);
        }

        return 'file://'.$this->encodeFilePath($path);
    }

    private function encodeFilePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);

        foreach ($segments as $index => $segment) {
            if ($segment === '' && $index === 0) {
                continue;
            }

            $segments[$index] = rawurlencode($segment);
        }

        return implode('/', $segments);
    }
}
