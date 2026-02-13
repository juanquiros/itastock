<?php

namespace App\Tests\Service;

use App\Service\ArcaSoapClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ArcaSoapClientFactoryTest extends TestCase
{
    public function testBuildWsdlCandidatesPrependsOverride(): void
    {
        $factory = $this->buildFactory();

        $candidates = $factory->buildWsdlCandidates([
            'https://default-1/wsdl',
            'https://default-2/wsdl',
        ], 'https://override/wsdl');

        self::assertSame('https://override/wsdl', $candidates[0]);
        self::assertCount(3, $candidates);
    }

    public function testDetectCaBundlesReturnsArray(): void
    {
        $factory = $this->buildFactory();

        $detected = $factory->detectCaBundles();

        self::assertIsArray($detected);
        foreach ($detected as $path) {
            self::assertIsString($path);
        }
    }

    private function buildFactory(): ArcaSoapClientFactory
    {
        return new ArcaSoapClientFactory(
            new NullLogger(),
            ['https://wsaa-homo/wsdl'],
            ['https://wsaa-prod/wsdl'],
            ['https://wsfe-homo/wsdl'],
            ['https://wsfe-prod/wsdl'],
            ['https://wsfe-homo/location'],
            ['https://wsfe-prod/location'],
            null,
            null,
            null,
            null,
            null,
            30,
            30,
            'ItaStock-ARCA-SOAP/1.0',
            true,
        );
    }
}
