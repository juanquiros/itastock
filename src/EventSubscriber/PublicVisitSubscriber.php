<?php

namespace App\EventSubscriber;

use App\Entity\PublicVisit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class PublicVisitSubscriber implements EventSubscriberInterface
{
    private const IGNORED_EXTENSIONS = [
        'css',
        'js',
        'png',
        'jpg',
        'jpeg',
        'svg',
        'webp',
        'ico',
        'map',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        ParameterBagInterface $parameterBag,
    ) {
        $this->appSecret = (string) $parameterBag->get('kernel.secret');
    }

    private string $appSecret;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $routeName = $request->attributes->get('_route');
        if (!is_string($routeName) || $routeName === '') {
            return;
        }

        if (!str_starts_with($routeName, 'public_')) {
            return;
        }

        $path = $request->getPathInfo();
        if (str_starts_with($path, '/platform') || str_starts_with($path, '/app')) {
            return;
        }

        if ($this->isAssetPath($path)) {
            return;
        }

        if ($request->getMethod() !== Request::METHOD_GET) {
            return;
        }

        $response = $event->getResponse();
        $statusCode = $response->getStatusCode();
        if (!$response->isSuccessful() && $statusCode !== Response::HTTP_NOT_FOUND) {
            return;
        }

        $ip = $request->getClientIp();
        if (!is_string($ip) || $ip === '') {
            return;
        }

        $visit = (new PublicVisit())
            ->setCreatedAt(new \DateTimeImmutable())
            ->setIp($ip)
            ->setIpHash(hash('sha256', $ip . $this->appSecret))
            ->setMethod($request->getMethod())
            ->setRouteName($this->trimToLength($routeName, 128))
            ->setPath($this->trimToLength($path, 255))
            ->setQueryString($request->getQueryString())
            ->setReferer($request->headers->get('referer'))
            ->setUserAgent($request->headers->get('user-agent'))
            ->setStatusCode($statusCode)
            ->setUtmSource($this->getQueryParam($request, 'utm_source'))
            ->setUtmMedium($this->getQueryParam($request, 'utm_medium'))
            ->setUtmCampaign($this->getQueryParam($request, 'utm_campaign'))
            ->setUtmContent($this->getQueryParam($request, 'utm_content'))
            ->setUtmTerm($this->getQueryParam($request, 'utm_term'));

        $this->entityManager->persist($visit);
        $this->entityManager->flush();
    }

    private function isAssetPath(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return false;
        }

        return in_array($extension, self::IGNORED_EXTENSIONS, true);
    }

    private function getQueryParam(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $this->trimToLength($value, 128);
    }

    private function trimToLength(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength);
    }
}
