<?php

declare(strict_types=1);

namespace Distantmagic\Resonance\SingletonProvider;

use Distantmagic\Resonance\Attribute\Singleton;
use Distantmagic\Resonance\DatabaseConfiguration;
use Distantmagic\Resonance\DatabaseConnectionPoolRepository;
use Distantmagic\Resonance\EventDispatcherInterface;
use Distantmagic\Resonance\PHPProjectFiles;
use Distantmagic\Resonance\SingletonContainer;
use Distantmagic\Resonance\SingletonProvider;
use PDO;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;

/**
 * @template-extends SingletonProvider<DatabaseConnectionPoolRepository>
 */
#[Singleton(provides: DatabaseConnectionPoolRepository::class)]
final readonly class DatabaseConnectionPoolRepositoryProvider extends SingletonProvider
{
    public function __construct(
        private DatabaseConfiguration $databaseConfiguration,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function provide(SingletonContainer $singletons, PHPProjectFiles $phpProjectFiles): DatabaseConnectionPoolRepository
    {
        $databaseConnectionPoolRepository = new DatabaseConnectionPoolRepository($this->eventDispatcher);

        foreach ($this->databaseConfiguration->connectionPoolConfiguration as $name => $connectionPoolConfiguration) {
            $pdoConfig = (new PDOConfig())
                ->withHost($connectionPoolConfiguration->host)
                ->withPort($connectionPoolConfiguration->port)
                ->withDbName($connectionPoolConfiguration->database)
                ->withDriver($connectionPoolConfiguration->driver->value)
                ->withUsername($connectionPoolConfiguration->username)
                ->withPassword($connectionPoolConfiguration->password)
            ;

            $pdoPool = new PDOPool($pdoConfig, $connectionPoolConfiguration->poolSize);

            if ($connectionPoolConfiguration->poolPrefill) {
                $pdoPool->fill();
            }

            $databaseConnectionPoolRepository->databaseConnectionPool->put($name, $pdoPool);
        }

        return $databaseConnectionPoolRepository;
    }
}
