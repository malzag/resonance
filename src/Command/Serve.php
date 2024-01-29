<?php

declare(strict_types=1);

namespace Distantmagic\Resonance\Command;

use Distantmagic\Resonance\ApplicationConfiguration;
use Distantmagic\Resonance\Attribute\ConsoleCommand;
use Distantmagic\Resonance\Command;
use Distantmagic\Resonance\Environment;
use Distantmagic\Resonance\Event\HttpServerBeforeStop;
use Distantmagic\Resonance\Event\HttpServerStarted;
use Distantmagic\Resonance\EventDispatcherInterface;
use Distantmagic\Resonance\HttpResponderAggregate;
use Distantmagic\Resonance\ServerPipeMessageDispatcher;
use Distantmagic\Resonance\SwooleConfiguration;
use Distantmagic\Resonance\WebSocketServerController;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as HttpServer;
use Swoole\Server;
use Swoole\WebSocket\Server as WebSocketServer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[ConsoleCommand(
    name: 'serve',
    description: 'Start combined HTTP and WebSocket server'
)]
final class Serve extends Command
{
    private readonly HttpServer|WebSocketServer $server;

    public function __construct(
        private readonly ApplicationConfiguration $applicationConfiguration,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly HttpResponderAggregate $httpResponderAggregate,
        private readonly LoggerInterface $logger,
        private readonly ServerPipeMessageDispatcher $serverPipeMessageDispatcher,
        private readonly SwooleConfiguration $swooleConfiguration,
        private readonly ?WebSocketServerController $webSocketServerController = null,
    ) {
        parent::__construct();

        $serverClass = $webSocketServerController
            ? WebSocketServer::class
            : HttpServer::class;

        /**
         * SWOOLE_SSL might not be defined in test environment.
         *
         * @psalm-suppress MixedArgument
         * @psalm-suppress UnusedPsalmSuppress
         * @psalm-suppress UndefinedConstant
         */
        $this->server = new $serverClass(
            $this->swooleConfiguration->host,
            $this->swooleConfiguration->port,
            SWOOLE_PROCESS,
            SWOOLE_SOCK_TCP | SWOOLE_SSL,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->server->set([
            'chroot' => DM_APP_ROOT,
            'enable_coroutine' => true,
            'enable_deadlock_check' => Environment::Development === $this->applicationConfiguration->environment,
            'enable_static_handler' => false,
            'http_autoindex' => false,
            'log_level' => $this->swooleConfiguration->logLevel,
            'ssl_cert_file' => DM_ROOT.'/'.$this->swooleConfiguration->sslCertFile,
            'ssl_key_file' => DM_ROOT.'/'.$this->swooleConfiguration->sslKeyFile,
            'open_http2_protocol' => true,
        ]);

        $this->server->on('beforeShutdown', $this->onBeforeShutdown(...));
        $this->server->on('pipeMessage', $this->serverPipeMessageDispatcher->dispatchPipeMessage(...));
        $this->server->on('request', $this->httpResponderAggregate->respond(...));
        $this->server->on('start', $this->onStart(...));

        if ($this->webSocketServerController) {
            $this->server->on('close', $this->onClose(...));
            $this->server->on('handshake', $this->onHandshake(...));
            $this->server->on('message', $this->webSocketServerController->onMessage(...));
            $this->server->on('open', $this->webSocketServerController->onOpen(...));
        }

        return (int) !$this->server->start();
    }

    private function onBeforeShutdown(): void
    {
        $this->eventDispatcher->dispatch(new HttpServerBeforeStop($this->server));
    }

    private function onClose(int $fd): void
    {
        $this->webSocketServerController?->onClose($fd);
    }

    private function onHandshake(Request $request, Response $response): void
    {
        if (!$this->webSocketServerController) {
            return;
        }
        if (!$this->server instanceof WebSocketServer) {
            return;
        }
        $this->webSocketServerController->onHandshake($this->server, $request, $response);
    }

    private function onStart(Server $server): void
    {
        $this->eventDispatcher->dispatch(new HttpServerStarted($server));

        $this->logger->info(sprintf(
            'http_server_start(https://%s:%s)',
            $this->swooleConfiguration->host,
            $this->swooleConfiguration->port,
        ));

        if (!$this->webSocketServerController) {
            return;
        }

        $this->logger->info(sprintf(
            'websocket_server_start(wss://%s:%s)',
            $this->swooleConfiguration->host,
            $this->swooleConfiguration->port,
        ));
    }
}
