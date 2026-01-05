<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\EmailNotificationLog;
use App\Entity\Subscription;
use App\Security\EmailContentPolicy;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class EmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly EmailContentPolicy $contentPolicy,
        private readonly string $mailFrom,
        private readonly string $appName,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function sendTemplatedEmail(
        string $type,
        string $to,
        string $role,
        string $subject,
        string $template,
        array $context,
        ?Business $business,
        ?Subscription $subscription,
        ?\DateTimeImmutable $periodStart,
        ?\DateTimeImmutable $periodEnd,
    ): string {
        $recipientEmail = mb_strtolower($to);
        $sanitizedContext = $this->contentPolicy->sanitizeContext($role, $type, $context);
        $subscription = $this->normalizeSubscription($subscription);
        $log = (new EmailNotificationLog())
            ->setType($type)
            ->setRecipientEmail($recipientEmail)
            ->setRecipientRole($role)
            ->setBusiness($business)
            ->setSubscription($subscription)
            ->setPeriodStart($periodStart)
            ->setPeriodEnd($periodEnd)
            ->setContextHash($this->hashContext($sanitizedContext));

        try {
            $this->contentPolicy->assertAllowedRecipientRole($role, $type);
        } catch (\DomainException $exception) {
            $log->setStatus(EmailNotificationLog::STATUS_SKIPPED)
                ->setErrorMessage($exception->getMessage());

            if (!$this->persistLogSafely($log)) {
                return EmailNotificationLog::STATUS_SKIPPED;
            }

            return EmailNotificationLog::STATUS_SKIPPED;
        }

        if ($this->isDuplicate($type, $recipientEmail, $subscription, $periodStart, $periodEnd)) {
            $log->setStatus(EmailNotificationLog::STATUS_SKIPPED)
                ->setErrorMessage('Duplicate notification detected.');

            if (!$this->persistLogSafely($log)) {
                return EmailNotificationLog::STATUS_SKIPPED;
            }

            return EmailNotificationLog::STATUS_SKIPPED;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, $this->appName))
            ->to($recipientEmail)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($sanitizedContext);

        $textTemplate = $this->resolveTextTemplate($template);
        if ($textTemplate) {
            $email->textTemplate($textTemplate);
        }

        try {
            $this->mailer->send($email);
            $log->setStatus(EmailNotificationLog::STATUS_SENT)
                ->setSentAt(new \DateTimeImmutable());
        } catch (\Throwable $exception) {
            $log->setStatus(EmailNotificationLog::STATUS_FAILED)
                ->setErrorMessage($exception->getMessage());
        }

        if (!$this->persistLogSafely($log)) {
            return EmailNotificationLog::STATUS_SKIPPED;
        }

        return $log->getStatus();
    }

    private function isDuplicate(
        string $type,
        string $recipientEmail,
        ?Subscription $subscription,
        ?\DateTimeImmutable $periodStart,
        ?\DateTimeImmutable $periodEnd,
    ): bool {
        $repository = $this->entityManager->getRepository(EmailNotificationLog::class);

        if (null !== $periodStart && null !== $periodEnd) {
            return null !== $repository->findOneBy([
                'type' => $type,
                'recipientEmail' => $recipientEmail,
                'periodStart' => $periodStart,
                'periodEnd' => $periodEnd,
            ]);
        }

        if (null !== $subscription) {
            return null !== $repository->findOneBy([
                'type' => $type,
                'recipientEmail' => $recipientEmail,
                'subscription' => $subscription,
                'periodStart' => null,
                'periodEnd' => null,
            ]);
        }

        return false;
    }

    private function hashContext(array $context): ?string
    {
        if ([] === $context) {
            return null;
        }

        return hash('sha256', serialize($context));
    }

    private function resolveTextTemplate(string $htmlTemplate): ?string
    {
        if (!str_ends_with($htmlTemplate, '.html.twig')) {
            return null;
        }

        $textTemplate = str_replace('.html.twig', '.txt.twig', $htmlTemplate);
        $path = rtrim($this->projectDir, '/').'/templates/'.$textTemplate;
        if (!is_file($path)) {
            return null;
        }

        return $textTemplate;
    }

    private function persistLogSafely(EmailNotificationLog $log): bool
    {
        if (!$this->entityManager->isOpen()) {
            $this->resetEntityManager();
        }

        try {
            $this->entityManager->persist($log);
            $this->entityManager->flush();

            return true;
        } catch (UniqueConstraintViolationException) {
            $this->resetEntityManager();

            return false;
        }
    }

    private function resetEntityManager(): void
    {
        if (!$this->entityManager->isOpen()) {
            $this->managerRegistry->resetManager();
            $this->entityManager = $this->managerRegistry->getManager();

            return;
        }

        $this->entityManager->clear(EmailNotificationLog::class);
    }

    private function normalizeSubscription(?Subscription $subscription): ?Subscription
    {
        if ($subscription === null) {
            return null;
        }

        $subscriptionId = $subscription->getId();
        if ($subscriptionId === null) {
            return null;
        }

        if ($this->entityManager->contains($subscription)) {
            return $subscription;
        }

        return $this->entityManager->getReference(Subscription::class, $subscriptionId);
    }
}
