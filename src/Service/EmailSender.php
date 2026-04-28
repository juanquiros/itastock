<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\EmailNotificationLog;
use App\Entity\Subscription;
use App\Security\EmailContentPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class EmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $entityManager,
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
        $log = (new EmailNotificationLog())
            ->setType($type)
            ->setRecipientEmail($recipientEmail)
            ->setRecipientRole($role)
            ->setBusiness($business)
            ->setSubscription($subscription)
            ->setPeriodStart($periodStart)
            ->setPeriodEnd($periodEnd)
            ->setContextHash($this->hashContext($sanitizedContext));
        $log->setIdempotencyKey($this->buildIdempotencyKey(
            $type,
            $recipientEmail,
            $subscription,
            $periodStart,
            $periodEnd,
            $log->getContextHash()
        ));

        try {
            $this->contentPolicy->assertAllowedRecipientRole($role, $type);
        } catch (\DomainException $exception) {
            $log->setStatus(EmailNotificationLog::STATUS_SKIPPED)
                ->setErrorMessage($exception->getMessage());

            $this->persistLog($log);

            return EmailNotificationLog::STATUS_SKIPPED;
        }

        $log->setStatus(EmailNotificationLog::STATUS_PROCESSING);
        if (!$this->reserveLog($log)) {
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

        $this->flushLog($log);

        return $log->getStatus();
    }

    private function buildIdempotencyKey(
        string $type,
        string $recipientEmail,
        ?Subscription $subscription,
        ?\DateTimeImmutable $periodStart,
        ?\DateTimeImmutable $periodEnd,
        ?string $contextHash,
    ): string {
        $scope = 'none';
        if (null !== $periodStart && null !== $periodEnd) {
            $scope = sprintf(
                'period:%s:%s',
                $periodStart->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
                $periodEnd->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)
            );
        } elseif (null !== $subscription && null !== $subscription->getId()) {
            $scope = sprintf('subscription:%d', $subscription->getId());
        } elseif (null !== $subscription) {
            $scope = 'subscription:new';
        }

        $raw = implode('|', [
            $type,
            mb_strtolower($recipientEmail),
            $scope,
            $contextHash ?? 'no-context',
        ]);

        return hash('sha256', $raw);
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

    private function reserveLog(EmailNotificationLog $log): bool
    {
        if (!$this->entityManager->isOpen()) {
            return false;
        }

        try {
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            if ($this->isUniqueViolation($exception)) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    private function flushLog(EmailNotificationLog $log): void
    {
        if (!$this->entityManager->isOpen()) {
            return;
        }

        try {
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            if ($this->isUniqueViolation($exception)) {
                return;
            }

            throw $exception;
        }
    }

    private function isUniqueViolation(\Throwable $exception): bool
    {
        if ($exception instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return true;
        }

        $cursor = $exception;
        while ($cursor !== null) {
            $message = mb_strtolower($cursor->getMessage());
            if (
                str_contains($message, 'unique constraint')
                || str_contains($message, 'duplicate entry')
                || str_contains($message, 'sqlstate[23000]')
                || str_contains($message, 'sqlstate[23505]')
            ) {
                return true;
            }

            $cursor = $cursor->getPrevious();
        }

        return false;
    }
}
