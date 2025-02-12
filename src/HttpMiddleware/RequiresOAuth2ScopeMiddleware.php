<?php

declare(strict_types=1);

namespace Distantmagic\Resonance\HttpMiddleware;

use Distantmagic\Resonance\Attribute;
use Distantmagic\Resonance\Attribute\GrantsFeature;
use Distantmagic\Resonance\Attribute\HandlesMiddlewareAttribute;
use Distantmagic\Resonance\Attribute\RequiresOAuth2Scope;
use Distantmagic\Resonance\Attribute\Singleton;
use Distantmagic\Resonance\AuthenticatedUserSource;
use Distantmagic\Resonance\AuthenticatedUserStoreAggregate;
use Distantmagic\Resonance\Feature;
use Distantmagic\Resonance\HttpInterceptableInterface;
use Distantmagic\Resonance\HttpMiddleware;
use Distantmagic\Resonance\HttpResponder\Error\Forbidden;
use Distantmagic\Resonance\HttpResponder\PsrResponder;
use Distantmagic\Resonance\HttpResponderInterface;
use Distantmagic\Resonance\HttpRouteMatchRegistry;
use Distantmagic\Resonance\OAuth2ClaimReader;
use Distantmagic\Resonance\OAuth2ScopeCollection;
use Distantmagic\Resonance\SingletonCollection;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * @template-extends HttpMiddleware<RequiresOAuth2Scope>
 */
#[GrantsFeature(Feature::OAuth2)]
#[HandlesMiddlewareAttribute(
    attribute: RequiresOAuth2Scope::class,
    priority: 1000,
)]
#[Singleton(collection: SingletonCollection::HttpMiddleware)]
readonly class RequiresOAuth2ScopeMiddleware extends HttpMiddleware
{
    public function __construct(
        private AuthenticatedUserStoreAggregate $authenticatedUserSourceAggregate,
        private Forbidden $forbidden,
        private HttpRouteMatchRegistry $routeMatchRegistry,
        private OAuth2ClaimReader $oAuth2ClaimReader,
        private OAuth2ScopeCollection $oAuth2ScopeCollection,
        private Psr17Factory $psr17Factory,
    ) {}

    public function preprocess(
        Request $request,
        Response $response,
        Attribute $attribute,
        HttpInterceptableInterface|HttpResponderInterface $next,
    ): HttpInterceptableInterface|HttpResponderInterface {
        $authenticatedUser = $this
            ->authenticatedUserSourceAggregate
            ->getAuthenticatedUser($request)
        ;

        if (!$authenticatedUser) {
            return $this->forbidden;
        }

        if (AuthenticatedUserSource::Session === $authenticatedUser->source) {
            return $next;
        }

        if (!$this->oAuth2ClaimReader->hasClaim($request)) {
            return $this->forbidden;
        }

        try {
            $oAuth2Claim = $this->oAuth2ClaimReader->readClaim($request);
            $routeVars = $this->routeMatchRegistry->get($request)->routeVars;

            $patternWithVariables = $attribute->pattern->withVariables($routeVars);

            foreach ($oAuth2Claim->scopes as $claimedScope) {
                if ($patternWithVariables === $claimedScope->getIdentifier()) {
                    return $next;
                }
            }
        } catch (OAuthServerException $exception) {
            $psrResponse = $this->psr17Factory->createResponse();

            return new PsrResponder($exception->generateHttpResponse($psrResponse));
        }

        return $this->forbidden;
    }
}
