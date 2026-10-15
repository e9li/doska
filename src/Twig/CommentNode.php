<?php

declare(strict_types=1);

namespace Doska\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;
use Twig\Node\NodeOutputInterface;

#[YieldReady]
final class CommentNode extends Node implements NodeOutputInterface
{
    public function __construct(string $comment, int $lineno)
    {
        parent::__construct([], ['comment' => $comment], $lineno);
    }

    public function compile(Compiler $compiler): void
    {
        $compiler
            ->write('yield ')
            ->string($this->getAttribute('comment'))
            ->raw(";\n");
    }
}
