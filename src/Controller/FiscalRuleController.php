<?php
namespace App\Controller;

use App\Entity\FiscalRule;
use App\Repository\FiscalRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/app/admin/fiscal-rules', name: 'app_fiscal_rule_')]
class FiscalRuleController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em){}
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(FiscalRuleRepository $repository): Response { $business=$this->getUser()?->getActiveBusiness(); return $this->render('fiscal_rule/index.html.twig',['rules'=>$repository->findForAdminList($business)]); }
    #[Route('/new', name: 'new', methods: ['GET','POST'])]
    public function new(Request $request): Response { return $this->upsert($request, new FiscalRule()); }
    #[Route('/{id}/edit', name: 'edit', methods: ['GET','POST'])]
    public function edit(Request $request, FiscalRule $rule): Response { return $this->upsert($request, $rule); }
    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(FiscalRule $rule): Response { $rule->setActive(!$rule->isActive()); $this->em->flush(); return $this->redirectToRoute('app_fiscal_rule_index'); }
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(FiscalRule $rule): Response { $this->em->remove($rule); $this->em->flush(); return $this->redirectToRoute('app_fiscal_rule_index'); }
    private function upsert(Request $request, FiscalRule $rule): Response { $business=$this->getUser()?->getActiveBusiness(); if($rule->getId()===null){$rule->setBusiness($business);} if($request->isMethod('POST')){ $d=$request->request; $rule->setName((string)$d->get('name',''))->setActive((bool)$d->get('active',false))->setPriority((int)$d->get('priority',100))->setComponentType((string)$d->get('componentType',FiscalRule::APPLIES_TO_GLOBAL))->setAppliesTo((string)$d->get('appliesTo',FiscalRule::APPLIES_TO_GLOBAL))->setJurisdiction($d->get('jurisdiction')?:null)->setDescriptionTemplate($d->get('descriptionTemplate')?:null)->setTaxableBaseMode((string)$d->get('taxableBaseMode',FiscalRule::TAXABLE_BASE_SALE_NET))->setRate($d->get('rate')?:null)->setFixedAmount($d->get('fixedAmount')?:null)->setMinAmount($d->get('minAmount')?:null)->setMaxAmount($d->get('maxAmount')?:null)->setArcaTributeId($d->get('arcaTributeId')!==''?(int)$d->get('arcaTributeId'):null)->setReportToArca((bool)$d->get('reportToArca',false))->setAffectsTotal((bool)$d->get('affectsTotal',false))->setIncludedInPrice((bool)$d->get('includedInPrice',false))->setStopProcessing((bool)$d->get('stopProcessing',false)); $this->em->persist($rule); $this->em->flush(); return $this->redirectToRoute('app_fiscal_rule_index'); } return $this->render('fiscal_rule/form.html.twig',['rule'=>$rule]); }
}
