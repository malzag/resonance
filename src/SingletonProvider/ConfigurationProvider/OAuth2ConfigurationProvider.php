<?php

declare(strict_types=1);

namespace Distantmagic\Resonance\SingletonProvider\ConfigurationProvider;

use Defuse\Crypto\Key;
use Distantmagic\Resonance\Attribute\GrantsFeature;
use Distantmagic\Resonance\Attribute\Singleton;
use Distantmagic\Resonance\Feature;
use Distantmagic\Resonance\JsonSchema;
use Distantmagic\Resonance\OAuth2Configuration;
use Distantmagic\Resonance\SingletonProvider\ConfigurationProvider;
use League\OAuth2\Server\CryptKey;
use RuntimeException;
use Swoole\Coroutine;

/**
 * @template-extends ConfigurationProvider<OAuth2Configuration, object{
 *     encryption_key: non-empty-string,
 *     jwt_signing_key_passphrase: null|string,
 *     jwt_signing_key_private: non-empty-string,
 *     jwt_signing_key_public: non-empty-string,
 *     session_key_authorization_request: non-empty-string,
 *     session_key_pkce: non-empty-string,
 *     session_key_state: non-empty-string,
 * }>
 */
#[GrantsFeature(Feature::OAuth2)]
#[Singleton(provides: OAuth2Configuration::class)]
final readonly class OAuth2ConfigurationProvider extends ConfigurationProvider
{
    public function getSchema(): JsonSchema
    {
        return new JsonSchema([
            'type' => 'object',
            'properties' => [
                'encryption_key' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'jwt_signing_key_passphrase' => [
                    'type' => 'string',
                    'default' => null,
                ],
                'jwt_signing_key_private' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'jwt_signing_key_public' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
                'session_key_authorization_request' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'default' => 'oauth2.authorization_request',
                ],
                'session_key_pkce' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'default' => 'oauth2.pkce',
                ],
                'session_key_state' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'default' => 'oauth2.state',
                ],
            ],
            'required' => ['encryption_key', 'jwt_signing_key_private', 'jwt_signing_key_public'],
        ]);
    }

    protected function getConfigurationKey(): string
    {
        return 'oauth2';
    }

    protected function provideConfiguration($validatedData): OAuth2Configuration
    {
        $encryptionKeyContent = Coroutine::readFile($validatedData->encryption_key);

        if (!is_string($encryptionKeyContent)) {
            throw new RuntimeException('Unable to read encrpytion key file: '.$validatedData->encryption_key);
        }

        return new OAuth2Configuration(
            encryptionKey: Key::loadFromAsciiSafeString($encryptionKeyContent),
            jwtSigningKeyPrivate: new CryptKey(
                DM_ROOT.'/'.$validatedData->jwt_signing_key_private,
                $validatedData->jwt_signing_key_passphrase,
            ),
            jwtSigningKeyPublic: new CryptKey(
                DM_ROOT.'/'.$validatedData->jwt_signing_key_public,
                $validatedData->jwt_signing_key_passphrase,
            ),
            sessionKeyAuthorizationRequest: $validatedData->session_key_authorization_request,
            sessionKeyPkce: $validatedData->session_key_pkce,
            sessionKeyState: $validatedData->session_key_state,
        );
    }
}
