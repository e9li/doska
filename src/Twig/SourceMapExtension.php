<?php

declare(strict_types=1);

namespace Doska\Twig;

use Twig\Extension\AbstractExtension;

final class SourceMapExtension extends AbstractExtension
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function getNodeVisitors(): array
    {
        return [new SourceMapNodeVisitor($this->projectDir)];
    }
}
