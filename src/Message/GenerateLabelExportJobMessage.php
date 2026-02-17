<?php

namespace App\Message;

class GenerateLabelExportJobMessage
{
    public function __construct(private readonly int $jobId)
    {
    }

    public function getJobId(): int
    {
        return $this->jobId;
    }
}
