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

        $relativePath = ltrim($relativePath, '/');

        $fullPath = $this->projectDir.'/public/'.$relativePath;

        if (!file_exists($fullPath)) {
            return null;
        }

        return 'file://'.str_replace('\\', '/', $fullPath);
    }
}
