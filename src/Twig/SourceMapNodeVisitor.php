<?php

declare(strict_types=1);

namespace Doska\Twig;

use Doska\Http\PathScope;
use Twig\Environment;
use Twig\Node\BlockNode;
use Twig\Node\BlockReferenceNode;
use Twig\Node\IncludeNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\TextNode;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Stamps literal opening tags with data-doska-loc and wraps blocks, includes,
 * and templates in source comments. The line on the attribute follows Annotai:
 * a re-render keeps the line on the element, and the comments carry the frame.
 */
final class SourceMapNodeVisitor implements NodeVisitorInterface
{
    /** @var 'script'|'style'|'textarea'|null */
    private ?string $raw = null;

    private bool $inComment = false;

    /** @var array<string, true> block names whose call site sits in raw text */
    private array $rawBlocks = [];

    private int $blockRawDepth = 0;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof ModuleNode) {
            $this->raw = null;
            $this->inComment = false;
            $this->rawBlocks = [];
            $this->blockRawDepth = 0;
        }

        if ($node instanceof BlockReferenceNode && $this->suspended()) {
            $this->rawBlocks[$node->getAttribute('name')] = true;
        }

        if ($node instanceof BlockNode && isset($this->rawBlocks[$node->getAttribute('name')])) {
            ++$this->blockRawDepth;
        }

        if (!$node instanceof TextNode || $this->blockRawDepth > 0) {
            return $node;
        }

        $source = $node->getSourceContext();
        $name = $source?->getName() ?? '';
        if (!$this->maps($name)) {
            return $node;
        }

        [$file] = PathScope::locate($name, $this->projectDir);
        $stamped = $this->stamp($node->getAttribute('data'), $file, $node->getTemplateLine());
        if ($stamped !== $node->getAttribute('data')) {
            $node->setAttribute('data', $stamped);
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        $sourceName = $node->getSourceContext()?->getName() ?? '';

        if ($node instanceof BlockNode && isset($this->rawBlocks[$node->getAttribute('name')])) {
            $this->blockRawDepth = max(0, $this->blockRawDepth - 1);

            return $node;
        }

        if ($node instanceof ModuleNode && $this->maps($sourceName)) {
            $node->getNode('display_start')->setNode(
                'doska_open',
                new RuntimeCommentNode('openTemplate', $sourceName),
            );
            $node->getNode('display_end')->setNode(
                'doska_close',
                new RuntimeCommentNode('closeTemplate', $sourceName),
            );

            return $node;
        }

        if (!$this->maps($sourceName) || $this->suspended()) {
            return $node;
        }

        if ($node instanceof BlockNode) {
            if (!$this->blockHasMarkup($node->getNode('body'))) {
                return $node;
            }
            [$file, $scope] = PathScope::locate($sourceName, $this->projectDir);
            $line = $node->getTemplateLine();
            $name = 'block:'.$node->getAttribute('name');
            $node->setNode('body', new Node([
                new CommentNode(Comments::open($name, $file, $line, $scope), $line),
                $node->getNode('body'),
                new CommentNode(Comments::close($name), $line),
            ]));

            return $node;
        }

        if ($node instanceof IncludeNode || $this->isComponent($node)) {
            [$file, $scope] = PathScope::locate($sourceName, $this->projectDir);
            $line = $node->getTemplateLine();

            return new Node([
                new CommentNode(Comments::caller($file, $line, $scope), $line),
                $node,
            ]);
        }

        return $node;
    }

    public function getPriority(): int
    {
        return 0;
    }

    private function maps(string $name): bool
    {
        return '' !== $name
            && PathScope::isHtmlTemplate($name)
            && !PathScope::isSkippedTemplate($name);
    }

    private function isComponent(Node $node): bool
    {
        $class = 'Symfony\\UX\\TwigComponent\\Twig\\ComponentNode';

        return class_exists($class) && $node instanceof $class;
    }

    /**
     * HTML comments inside script, style, or textarea would break the page.
     * The same is true for a caller comment around an include that sits in
     * one of those regions, which is how Drehscheibe embeds main.js.twig.
     */
    /**
     * A text-only block, such as a page title, must not gain HTML comments.
     * Browsers treat comments inside title as text.
     */
    private function blockHasMarkup(Node $node): bool
    {
        if ($node instanceof TextNode && preg_match('/<[a-zA-Z]/', $node->getAttribute('data'))) {
            return true;
        }
        if ($node instanceof IncludeNode || $this->isComponent($node)) {
            return true;
        }
        foreach ($node as $child) {
            if ($child instanceof Node && $this->blockHasMarkup($child)) {
                return true;
            }
        }

        return false;
    }

    private function suspended(): bool
    {
        return null !== $this->raw || $this->inComment || $this->blockRawDepth > 0;
    }

    /**
     * Insert data-doska-loc on every literal opening tag. The line is the text
     * node's start line plus the newlines before that tag, so a multi-line
     * chunk still points at the tag. script, style, and textarea contents
     * are copied through, and the raw-text state survives the next Twig tag.
     */
    private function stamp(string $data, string $file, int $baseLine): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($data);
        $fileAttr = htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($this->inComment) {
            $end = strpos($data, '-->');
            if (false === $end) {
                return $data;
            }
            $out .= substr($data, 0, $end + 3);
            $offset = $end + 3;
            $this->inComment = false;
        }

        while ($offset < $length) {
            if (null !== $this->raw) {
                $close = '</'.$this->raw;
                $pos = $this->findClose($data, $close, $offset);
                if (null === $pos) {
                    return $out.substr($data, $offset);
                }
                $out .= substr($data, $offset, $pos - $offset + strlen($close));
                $offset = $pos + strlen($close);
                $this->raw = null;
                continue;
            }

            if (!preg_match(
                '/<(!--|\/?([a-zA-Z][a-zA-Z0-9:-]*))(?=[\s>\/])/',
                $data,
                $matches,
                PREG_OFFSET_CAPTURE,
                $offset,
            )) {
                break;
            }

            $start = $matches[0][1];
            if ($start < $offset) {
                break;
            }
            $token = $matches[1][0];
            $out .= substr($data, $offset, $start - $offset);

            if ('!--' === $token) {
                $end = strpos($data, '-->', $start);
                if (false === $end) {
                    $this->inComment = true;

                    return $out.substr($data, $start);
                }
                $out .= substr($data, $start, $end + 3 - $start);
                $offset = $end + 3;
                continue;
            }

            $closing = str_starts_with($token, '/');
            $tag = strtolower($closing ? substr($token, 1) : $token);
            $namePos = $matches[2][1];
            $out .= substr($data, $start, $namePos + strlen($tag) - $start);

            if (!$closing) {
                $after = substr($data, $namePos + strlen($tag), 16);
                if (!str_starts_with($after, ' data-doska-loc=')) {
                    $line = $baseLine + substr_count(substr($data, 0, $namePos), "\n");
                    $out .= ' data-doska-loc="'.$fileAttr.':'.$line.'"';
                }
                if (in_array($tag, ['script', 'style', 'textarea', 'title'], true)) {
                    $this->raw = $tag;
                }
            }

            $offset = $namePos + strlen($tag);
        }

        return $out.substr($data, $offset);
    }

    private function findClose(string $data, string $close, int $offset): ?int
    {
        $pos = stripos($data, $close, $offset);
        while (false !== $pos) {
            $after = $data[$pos + strlen($close)] ?? '>';
            if (1 === preg_match('/[\s>\/]/', $after)) {
                return $pos;
            }
            $pos = stripos($data, $close, $pos + strlen($close));
        }

        return null;
    }
}
