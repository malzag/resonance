<?php

declare(strict_types=1);

namespace Distantmagic\Resonance;

use SensitiveParameter;

readonly class SwooleConfiguration
{
    /**
     * @psalm-taint-source file $sslCertFile
     * @psalm-taint-source file $sslKeyFile
     * @psalm-taint-source system_secret $host
     * @psalm-taint-source system_secret $port
     */
    public function __construct(
        #[SensitiveParameter]
        public string $host,
        public int $logLevel,
        public bool $logRequests,
        #[SensitiveParameter]
        public int $port,
        #[SensitiveParameter]
        public string $sslCertFile,
        #[SensitiveParameter]
        public string $sslKeyFile,
    ) {}
}
