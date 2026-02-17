<?php

namespace App\Service;

use App\Entity\LabelExportJob;

class LabelExportFilesystem
{
    public function getJobDir(LabelExportJob $job): string
    {
        return sprintf('%s/var/exports/labels/%d/%d', dirname(__DIR__, 2), $job->getBusiness()?->getId(), $job->getId());
    }

    public function ensureJobDir(LabelExportJob $job): string
    {
        $dir = $this->getJobDir($job);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
