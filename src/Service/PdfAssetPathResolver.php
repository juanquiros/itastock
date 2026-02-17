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
