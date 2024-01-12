<?php

declare(strict_types=1);

namespace Distantmagic\Resonance;

use Distantmagic\Resonance\HttpResponder\HttpController;
use LogicException;

readonly class OpenAPISchemaOperation implements OpenAPISerializableFieldInterface
{
    private HttpControllerReflectionMethod $httpControllerReflectionMethod;

    public function __construct(
        HttpControllerReflectionMethodCollection $httpControllerReflectionMethodCollection,
        private OpenAPIPathItem $openAPIPathItem,
        private OpenAPIRouteParameterExtractorAggregate $openAPIRouteParameterExtractorAggregate,
        private OpenAPIRouteRequestBodyContentExtractorAggregate $openAPIRouteRequestBodyContentExtractorAggregate,
    ) {
        $httpResponderClass = $this->openAPIPathItem->reflectionClass->getName();

        if (!is_a($httpResponderClass, HttpController::class, true)) {
            throw new LogicException(sprintf(
                'OpenAPI parameters can only be inferred from "%s", got "%s"',
                HttpController::class,
                $httpResponderClass,
            ));
        }

        $this->httpControllerReflectionMethod = $httpControllerReflectionMethodCollection
            ->reflectionMethods
            ->get($httpResponderClass)
        ;
    }

    public function toArray(OpenAPIReusableSchemaCollection $openAPIReusableSchemaCollection): array
    {
        $operation = [];

        $operation['operationId'] = $this->generateOperationId();

        if (isset($this->openAPIPathItem->respondsToHttp->description)) {
            $operation['description'] = $this->openAPIPathItem->respondsToHttp->description;
        }

        if (isset($this->openAPIPathItem->respondsToHttp->summary)) {
            $operation['summary'] = $this->openAPIPathItem->respondsToHttp->summary;
        }

        $parameters = $this->serializeParameters();

        if (!empty($parameters)) {
            $operation['parameters'] = $parameters;
        }

        $requestBodyContents = $this->serializeRequestBodyContents($openAPIReusableSchemaCollection);

        if (!empty($requestBodyContents)) {
            $operation['requestBody'] = [
                'content' => $requestBodyContents,
            ];
        }

        $security = $this->serializeSecurity();

        if (!empty($security)) {
            $operation['security'] = $security;
        }

        return $operation;
    }

    private function generateOperationId(): string
    {
        return sprintf(
            '%s%s',
            ucfirst(strtolower($this->openAPIPathItem->respondsToHttp->method->value)),
            str_replace('\\', '', $this->openAPIPathItem->reflectionClass->getName()),
        );
    }

    private function serializeParameters(): array
    {
        $parameters = [];

        foreach ($this->httpControllerReflectionMethod->parameters as $reflectionMethodParameter) {
            if ($reflectionMethodParameter->attribute) {
                $extractedParameters = $this
                    ->openAPIRouteParameterExtractorAggregate
                    ->extractFromHttpControllerParameter(
                        $reflectionMethodParameter->attribute,
                        $reflectionMethodParameter->className,
                        $reflectionMethodParameter->name,
                    )
                ;

                foreach ($extractedParameters as $parameter) {
                    $parameters[] = $parameter;
                }
            }
        }

        return $parameters;
    }

    // private function serializeRequiredOAuth2Scopes(): array
    // {
    //     $scopes = [];

    //     // foreach ($this->openAPIPathItem->requiredOAuth2Scopes as $scope) {
    //     //     $scopes[] = $scope->pattern->pattern;
    //     // }

    //     return $scopes;
    // }

    private function serializeRequestBodyContents(
        OpenAPIReusableSchemaCollection $openAPIReusableSchemaCollection,
    ): array {
        $requestBodyContents = [];

        foreach ($this->httpControllerReflectionMethod->parameters as $reflectionMethodParameter) {
            if ($reflectionMethodParameter->attribute) {
                $parameterResolvedValue = $this
                    ->openAPIRouteRequestBodyContentExtractorAggregate
                    ->extractFromHttpControllerParameter(
                        $reflectionMethodParameter->attribute,
                        $reflectionMethodParameter->className,
                        $reflectionMethodParameter->name,
                    )
                ;

                foreach ($parameterResolvedValue as $requestBodyContent) {
                    if (isset($requestBodyContents[$requestBodyContent->mimeType])) {
                        throw new LogicException(sprintf(
                            'Ambiguous request body resolution in "%s"',
                            $reflectionMethodParameter->className,
                        ));
                    }

                    $requestBodyContents[$requestBodyContent->mimeType] = $requestBodyContent->toArray($openAPIReusableSchemaCollection);
                }
            }
        }

        return $requestBodyContents;
    }

    private function serializeSecurity(): array
    {
        return [];

        // if (!$this->openAPIPathItem->requiredOAuth2Scopes->isEmpty()) {
        //     $security[OpenAPISecuritySchema::OAuth2->name] = $this->serializeRequiredOAuth2Scopes();
        // }
    }
}
