<?php
namespace App\Service;

use App\Entity\Business;
use App\Entity\FiscalComponent;

class FiscalManualComponentFactory
{
    public function buildForSale(Business $business, array $payload): array { return $this->build($business, $payload, FiscalComponent::SOURCE_SALE); }
    public function buildForPurchaseInvoice(Business $business, array $payload): array { return $this->build($business, $payload, FiscalComponent::SOURCE_PURCHASE_INVOICE); }

    public function normalizeMoney(mixed $value): string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') { return '0.00'; }
        if (str_contains($s, '-') || preg_match('/[^0-9,\.]/', $s)) { throw new \InvalidArgumentException('Importe inválido.'); }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $lastComma = strrpos($s, ','); $lastDot = strrpos($s, '.');
            if ($lastComma > $lastDot) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
            else { $s = str_replace(',', '', $s); }
        } elseif (str_contains($s, ',')) {
            $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s);
        }
        if (!preg_match('/^\d+(\.\d{1,8})?$/', $s)) { throw new \InvalidArgumentException('Importe inválido.'); }
        return bcadd($s, '0', 2);
    }

    public function normalizeRate(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') { return null; }
        $s = str_replace(',', '.', $s);
        if (str_contains($s, '-') || !preg_match('/^\d+(\.\d{1,8})?$/', $s)) { throw new \InvalidArgumentException('Alícuota inválida.'); }
        return bcadd($s, '0', 4);
    }

    public function validateComponentType(string $type): void
    {
        $allowed=[FiscalComponent::TYPE_INTERNAL_TAX,FiscalComponent::TYPE_IIBB_PERCEPTION,FiscalComponent::TYPE_VAT_PERCEPTION,FiscalComponent::TYPE_MUNICIPAL_TAX,FiscalComponent::TYPE_NATIONAL_OTHER_TAX,FiscalComponent::TYPE_OTHER];
        if(!in_array($type,$allowed,true)){ throw new \InvalidArgumentException('Tipo de componente fiscal inválido.'); }
    }

    private function parseBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) { return $value; }
        if ($value === null) { return false; }
        $s = strtolower(trim((string) $value));
        if (in_array($s, ['1','true','on','yes','si','sí'], true)) { return true; }
        if (in_array($s, ['0','false','off','no',''], true)) { return false; }
        return $default;
    }

    private function build(Business $business, array $payload, string $source): array
    {
        $rows = $payload['fiscal_components'] ?? $payload; $items=[];
        foreach($rows as $row){ if(!is_array($row)){continue;} $description=trim((string)($row['description'] ?? '')); $type=(string)($row['componentType'] ?? $row['component_type'] ?? ''); $amountRaw=(string)($row['amount'] ?? '');
            if($description==='' && $type==='' && trim($amountRaw)===''){continue;}
            if($description==='' || $type===''){ throw new \InvalidArgumentException('Cada tributo manual debe tener tipo y descripción.'); }
            $this->validateComponentType($type);
            $c=(new FiscalComponent())->setBusiness($business)->setSourceType($source)->setMode(FiscalComponent::MODE_MANUAL)->setComponentType($type)->setDescription($description)->setJurisdiction(($row['jurisdiction'] ?? null) ?: null)->setArcaTributeId(isset($row['arcaTributeId']) && $row['arcaTributeId']!=='' ? (int)$row['arcaTributeId'] : null)->setTaxableBase($this->normalizeMoney($row['taxableBase'] ?? '0'))->setRate($this->normalizeRate($row['rate'] ?? null))->setAmount($this->normalizeMoney($row['amount'] ?? '0'))->setAffectsTotal($this->parseBool($row['affectsTotal'] ?? true, true))->setReportToArca($this->parseBool($row['reportToArca'] ?? true, true))->setIncludedInPrice($this->parseBool($row['includedInPrice'] ?? false, false));
            $items[]=$c;
        }
        return $items;
    }
}
