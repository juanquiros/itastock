<?php

namespace App\Service;

use App\Entity\Business;
use App\Entity\EmailNotificationLog;
use App\Entity\Subscription;
use App\Security\EmailContentPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
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

        try {
            $this->contentPolicy->assertAllowedRecipientRole($role, $type);
        } catch (\DomainException $exception) {
            $log->setStatus(EmailNotificationLog::STATUS_SKIPPED)
                ->setErrorMessage($exception->getMessage());

            $this->entityManager->persist($log);
            $this->entityManager->flush();

            return EmailNotificationLog::STATUS_SKIPPED;
        }

        if ($this->isDuplicate($type, $recipientEmail, $subscription, $periodStart, $periodEnd)) {
            $log->setStatus(EmailNotificationLog::STATUS_SKIPPED)
                ->setErrorMessage('Duplicate notification detected.');

            $this->entityManager->persist($log);
            $this->entityManager->flush();

            return EmailNotificationLog::STATUS_SKIPPED;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, $this->appName))
            ->to($recipientEmail)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($sanitizedContext);

        try {
            $this->mailer->send($email);
            $log->setStatus(EmailNotificationLog::STATUS_SENT)
                ->setSentAt(new \DateTimeImmutable());
        } catch (\Throwable $exception) {
            $log->setStatus(EmailNotificationLog::STATUS_FAILED)
                ->setErrorMessage($exception->getMessage());
        }

        $this->entityManager->persist($log);
        $this->entityManager->flush();

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
}
