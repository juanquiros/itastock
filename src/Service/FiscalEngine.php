<?php
namespace App\Service;

use App\Entity\Business;
use App\Entity\FiscalComponent;
use App\Entity\FiscalRule;
use App\Entity\Sale;
use App\Entity\SaleItem;
use App\Repository\FiscalRuleRepository;
use App\Service\Result\FiscalCalculationResult;

class FiscalEngine
{
    public function __construct(private readonly FiscalRuleRepository $fiscalRuleRepository){}

    public function calculateForSale(Business $business, Sale $sale, array $manualComponents = []): FiscalCalculationResult
    {
        $rules = $this->fiscalRuleRepository->findActiveForBusiness($business);
        $automatic = [];
        $warnings = [];
        foreach ($rules as $rule) {
            if (!$rule instanceof FiscalRule || !$this->matches($rule, $sale)) { continue; }
            [$base, $baseWarning] = $this->resolveTaxableBase($rule, $sale);
            if ($baseWarning) { $warnings[] = $baseWarning; continue; }
            if ($rule->getMinAmount() !== null && bccomp($base, $rule->getMinAmount(), 2) < 0) { continue; }
            if ($rule->getMaxAmount() !== null && bccomp($base, $rule->getMaxAmount(), 2) > 0) { continue; }
            $amount = '0.00';
            if ($rule->getRate() !== null) { $amount = bcdiv(bcmul($base, $rule->getRate(), 4), '100', 2); }
            if ($rule->getFixedAmount() !== null) { $amount = bcadd($amount, $rule->getFixedAmount(), 2); }
            if (bccomp($amount, '0', 2) < 0) { $amount = '0.00'; }
            if (bccomp($amount, '0', 2) === 0 && $rule->getFixedAmount() === null && $rule->getRate() === null) { continue; }
            $c=(new FiscalComponent())->setBusiness($business)->setSourceType(FiscalComponent::SOURCE_SALE)->setMode(FiscalComponent::MODE_AUTO_RULE)->setComponentType($rule->getComponentType())->setDescription($rule->getDescriptionTemplate() ?: $rule->getName())->setJurisdiction($rule->getJurisdiction())->setArcaTributeId($rule->getArcaTributeId())->setTaxableBase($base)->setRate($rule->getRate())->setAmount($amount)->setAffectsTotal($rule->isAffectsTotal())->setReportToArca($rule->isReportToArca())->setIncludedInPrice($rule->isIncludedInPrice())->setMetadata(['fiscalRuleId'=>$rule->getId(),'fiscalRuleName'=>$rule->getName(),'taxableBaseMode'=>$rule->getTaxableBaseMode(),'appliesTo'=>$rule->getAppliesTo()]);
            $automatic[] = $c;
            if ($rule->isStopProcessing()) { break; }
        }
        $all = array_merge($manualComponents, $automatic);
        $total='0.00'; foreach($all as $c){ if($c instanceof FiscalComponent && $c->isAffectsTotal()){$total=bcadd($total,$c->getAmount(),2);} }
        return new FiscalCalculationResult($manualComponents, $automatic, $all, $total, $warnings);
    }

    private function matches(FiscalRule $rule, Sale $sale): bool
    {
        return match ($rule->getAppliesTo()) {
            FiscalRule::APPLIES_TO_GLOBAL => true,
            FiscalRule::APPLIES_TO_CUSTOMER => $sale->getCustomer() && $rule->getCustomer() && $sale->getCustomer()->getId() === $rule->getCustomer()->getId(),
            FiscalRule::APPLIES_TO_CUSTOMER_IVA_CONDITION => $sale->getCustomer() && $rule->getCustomerIvaConditionId() !== null && $sale->getCustomer()->getIvaConditionId() === $rule->getCustomerIvaConditionId(),
            FiscalRule::APPLIES_TO_PRODUCT => $this->sumMatchedItemNet($sale, static fn(SaleItem $i) => $rule->getProduct() && $i->getProduct()?->getId()===$rule->getProduct()?->getId()) !== '0.00',
            FiscalRule::APPLIES_TO_CATEGORY => $this->sumMatchedItemNet($sale, static fn(SaleItem $i) => $rule->getCategory() && $i->getProduct()?->getCategory()?->getId()===$rule->getCategory()?->getId()) !== '0.00',
            default => false,
        };
    }

    private function resolveTaxableBase(FiscalRule $rule, Sale $sale): array
    {
        $mode = $rule->getTaxableBaseMode();
        if ($mode === FiscalRule::TAXABLE_BASE_SALE_NET) { $base = bcsub($sale->getSubtotal() ?? '0.00', $sale->getDiscountTotal() ?? '0.00', 2); return [$this->maxMoney($base), null]; }
        if ($mode === FiscalRule::TAXABLE_BASE_SALE_TOTAL) { return [$this->maxMoney($sale->getTotal() ?? '0.00'), null]; }
        if ($mode === FiscalRule::TAXABLE_BASE_ITEM_NET) {
            if ($rule->getAppliesTo() === FiscalRule::APPLIES_TO_PRODUCT) { return [$this->sumMatchedItemNet($sale, fn(SaleItem $i)=>$rule->getProduct()&&$i->getProduct()?->getId()===$rule->getProduct()?->getId()), null]; }
            if ($rule->getAppliesTo() === FiscalRule::APPLIES_TO_CATEGORY) { return [$this->sumMatchedItemNet($sale, fn(SaleItem $i)=>$rule->getCategory()&&$i->getProduct()?->getCategory()?->getId()===$rule->getCategory()?->getId()), null]; }
            return [$this->maxMoney($this->sumMatchedItemNet($sale, fn()=>true)), null];
        }
        if ($mode === FiscalRule::TAXABLE_BASE_MANUAL_BASE) {
            if ($rule->getFixedAmount() === null) { return ['0.00', sprintf('Regla "%s": MANUAL_BASE requiere monto fijo.', $rule->getName())]; }
            return ['0.00', null];
        }
        return ['0.00', null];
    }
    private function maxMoney(string $value): string { return bccomp($value, '0.00', 2) < 0 ? '0.00' : bcadd($value, '0.00', 2); }
    private function sumMatchedItemNet(Sale $sale, callable $matcher): string { $total='0.00'; foreach($sale->getItems() as $item){ if($item instanceof SaleItem && $matcher($item)){ $total=bcadd($total,$item->getLineTotal(),2);} } return $total; }
}
