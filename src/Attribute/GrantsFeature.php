<?php

declare(strict_types=1);

namespace Distantmagic\Resonance\Attribute;

use Attribute;
use Distantmagic\Resonance\Attribute as BaseAttribute;
use Distantmagic\Resonance\FeatureInterface;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class GrantsFeature extends BaseAttribute
{
    public function __construct(
        public FeatureInterface $feature,
    ) {}
}
