<?php
namespace App\Service;

use App\Entity\Business;
use App\Entity\FiscalComponent;

class FiscalManualComponentFactory
{
    public function buildForSale(Business $business, array $payload): array { return $this->build($business, $payload, FiscalComponent::SOURCE_SALE); }
    public function buildForPurchaseInvoice(Business $business, array $payload): array { return $this->build($business, $payload, FiscalComponent::SOURCE_PURCHASE_INVOICE); }
    public function normalizeMoney(mixed $value): string { $v=str_replace(',', '.', trim((string)($value ?? '0'))); if($v===''){$v='0';} if(!is_numeric($v)){ throw new \InvalidArgumentException('Importe inválido.'); } if((float)$v<0){ throw new \InvalidArgumentException('No se permiten importes negativos.'); } return number_format((float)$v,2,'.',''); }
    public function normalizeRate(mixed $value): ?string { $v=trim((string)($value ?? '')); if($v===''){return null;} $v=str_replace(',', '.', $v); if(!is_numeric($v) || (float)$v<0){ throw new \InvalidArgumentException('Alícuota inválida.'); } return number_format((float)$v,4,'.',''); }
    public function validateComponentType(string $type): void { $allowed=[FiscalComponent::TYPE_INTERNAL_TAX,FiscalComponent::TYPE_IIBB_PERCEPTION,FiscalComponent::TYPE_VAT_PERCEPTION,FiscalComponent::TYPE_MUNICIPAL_TAX,FiscalComponent::TYPE_NATIONAL_OTHER_TAX,FiscalComponent::TYPE_OTHER]; if(!in_array($type,$allowed,true)){ throw new \InvalidArgumentException('Tipo de componente fiscal inválido.'); }}
    private function build(Business $business, array $payload, string $source): array { $rows = $payload['fiscal_components'] ?? $payload; $items=[]; foreach($rows as $row){ if(!is_array($row)){continue;} $description=trim((string)($row['description'] ?? '')); $type=(string)($row['componentType'] ?? $row['component_type'] ?? ''); $amountRaw=(string)($row['amount'] ?? ''); if($description==='' && $type==='' && trim($amountRaw)===''){continue;} if($description==='' || $type===''){ throw new \InvalidArgumentException('Cada tributo manual debe tener tipo y descripción.'); } $this->validateComponentType($type); $c=(new FiscalComponent())->setBusiness($business)->setSourceType($source)->setMode(FiscalComponent::MODE_MANUAL)->setComponentType($type)->setDescription($description)->setJurisdiction(($row['jurisdiction'] ?? null) ?: null)->setArcaTributeId(isset($row['arcaTributeId']) && $row['arcaTributeId']!=='' ? (int)$row['arcaTributeId'] : null)->setTaxableBase($this->normalizeMoney($row['taxableBase'] ?? '0'))->setRate($this->normalizeRate($row['rate'] ?? null))->setAmount($this->normalizeMoney($row['amount'] ?? '0'))->setAffectsTotal((bool)($row['affectsTotal'] ?? true))->setReportToArca((bool)($row['reportToArca'] ?? true))->setIncludedInPrice((bool)($row['includedInPrice'] ?? false)); $items[]=$c; } return $items; }
}
