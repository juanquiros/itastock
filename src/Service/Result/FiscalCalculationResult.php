<?php
namespace App\Service\Result;
class FiscalCalculationResult
{
    public function __construct(private array $manualComponents, private array $automaticComponents, private array $suggestedComponents, private array $allComponents, private string $fiscalComponentsTotal, private array $warnings = []){}
    public function getManualComponents(): array { return $this->manualComponents; }
    public function getAutomaticComponents(): array { return $this->automaticComponents; }
    public function getSuggestedComponents(): array { return $this->suggestedComponents; }
    public function getAllComponents(): array { return $this->allComponents; }
    public function getFiscalComponentsTotal(): string { return $this->fiscalComponentsTotal; }
    public function getWarnings(): array { return $this->warnings; }
    public function hasWarnings(): bool { return $this->warnings !== []; }
}
