<?php

declare(strict_types=1);

namespace Doska\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;
use Twig\Node\NodeOutputInterface;

/**
 * Yields a source comment from SourceMapRuntime at render time, so the
 * component class file can be resolved after the container is built.
 */
#[YieldReady]
final class RuntimeCommentNode extends Node implements NodeOutputInterface
{
    public function __construct(
        private readonly string $method,
        private readonly string $template,
        int $lineno = 0,
    ) {
        parent::__construct([], [], $lineno);
    }

    public function compile(Compiler $compiler): void
    {
        $compiler
            ->write('yield $this->env->getRuntime(')
            ->string(SourceMapRuntime::class)
            ->raw(')->')
            ->raw($this->method)
            ->raw('(')
            ->string($this->template)
            ->raw(");\n");
    }
}
