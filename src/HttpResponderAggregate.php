<?php

declare(strict_types=1);

namespace Distantmagic\Resonance;

use Distantmagic\Resonance\Attribute\Singleton;
use Distantmagic\Resonance\Event\HttpResponseReady;
use Distantmagic\Resonance\Event\UnhandledException;
use Distantmagic\Resonance\HttpResponder\Error\MethodNotAllowed;
use Distantmagic\Resonance\HttpResponder\Error\PageNotFound;
use Distantmagic\Resonance\HttpResponder\Error\ServerError;
use DomainException;
use LogicException;
use RuntimeException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Throwable;

#[Singleton]
readonly class HttpResponderAggregate
{
    public function __construct(
        private ApplicationConfiguration $applicationConfiguration,
        private EventDispatcherInterface $eventDispatcher,
        private HttpRecursiveResponder $recursiveResponder,
        private HttpResponderCollection $httpResponderCollection,
        private HttpRouteMatchRegistry $routeMatchRegistry,
        private MethodNotAllowed $methodNotAllowed,
        private PageNotFound $pageNotFound,
        private RequestContext $requestContext,
        private ServerError $serverError,
        private UrlMatcher $urlMatcher,
    ) {}

    public function respond(Request $request, Response $response): void
    {
        $responder = $this->selectResponder($request);

        try {
            $this->recursiveResponder->respondRecursive($request, $response, $responder);
        } catch (Throwable $throwable) {
            $this->eventDispatcher->dispatch(new UnhandledException($throwable));
            $this->recursiveResponder->respondRecursive(
                $request,
                $response,
                $this->serverError->sendThrowable($request, $response, $throwable),
            );
        } finally {
            $this->eventDispatcher->dispatch(new HttpResponseReady($responder, $request));
        }
    }

    /**
     * @param null|class-string<HttpResponderInterface> $responderClass
     */
    private function matchResponder(
        HttpRouteMatchStatus $routeStatus,
        ?string $responderClass,
    ): HttpResponderInterface {
        return match ($routeStatus) {
            HttpRouteMatchStatus::MethodNotAllowed => $this->methodNotAllowed,
            HttpRouteMatchStatus::NotFound => $this->pageNotFound,
            HttpRouteMatchStatus::Found => $this->resolveFoundResponder($responderClass),

            default => throw new DomainException('Unexpected route status'),
        };
    }

    private function matchRoute(Request $request): HttpRouteMatch
    {
        if (!is_array($request->server)) {
            throw new RuntimeException('Unable to determine the request server vars');
        }

        if (!isset($request->server['request_method']) || !is_string($request->server['request_method'])) {
            throw new RuntimeException('Unable to determine the request method');
        }

        if (!isset($request->server['request_uri']) || !is_string($request->server['request_uri'])) {
            throw new RuntimeException('Unable to determine the request uri');
        }

        $this
            ->requestContext
            ->setMethod($request->server['request_method'])
            ->setPathInfo((string) $request->server['path_info'])
            ->setHost((string) $request->server['remote_addr'])
            ->setHttpsPort((int) $request->server['server_port'])
            ->setScheme($this->applicationConfiguration->scheme)
        ;

        try {
            /**
             * @var array<string,string>
             */
            $routeMatch = $this->urlMatcher->match((string) $request->server['path_info']);
            $responderClass = $routeMatch['_route'];

            if (!is_a($responderClass, HttpResponderInterface::class, true)) {
                throw new LogicException('Matched route does not resolve to an instance of HttpResponderInterface');
            }

            unset($routeMatch['_route']);

            return new HttpRouteMatch(
                responderClass: $responderClass,
                routeVars: $routeMatch,
                status: HttpRouteMatchStatus::Found,
            );
        } catch (MethodNotAllowedException) {
            return new HttpRouteMatch(HttpRouteMatchStatus::MethodNotAllowed);
        } catch (ResourceNotFoundException) {
            return new HttpRouteMatch(HttpRouteMatchStatus::NotFound);
        }
    }

    /**
     * @param null|class-string<HttpResponderInterface> $responderClass
     */
    private function resolveFoundResponder(?string $responderClass): HttpResponderInterface
    {
        if (!$responderClass || !$this->httpResponderCollection->httpResponders->hasKey($responderClass)) {
            $this->unimplementedHttpRouteResponder($responderClass);
        }

        return $this->httpResponderCollection->httpResponders->get($responderClass);
    }

    private function selectResponder(Request $request): HttpResponderInterface
    {
        $routeMatchingStatus = $this->matchRoute($request);
        $this->routeMatchRegistry->set($request, $routeMatchingStatus);

        return $this->matchResponder(
            $routeMatchingStatus->status,
            $routeMatchingStatus->responderClass,
        );
    }

    /**
     * @param null|class-string<HttpResponderInterface> $responderClass
     */
    private function unimplementedHttpRouteResponder(?string $responderClass): never
    {
        if ($responderClass) {
            throw new DomainException('Unresolved route responder: '.$responderClass);
        }

        throw new DomainException('Unresolved route responder.');
    }
}
