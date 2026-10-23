<?php

declare(strict_types=1);

namespace Doska\Kirby\Tests;

use Doska\Twig\Comments;
use PHPUnit\Framework\TestCase;

final class FramesTest extends TestCase
{
    public function testCommentGrammarMatchesTheToolbar(): void
    {
        $open = Comments::open(
            'snippet:blocks/sectionhero',
            'site/snippets/blocks/sectionhero.php',
            1,
            'app',
            'content content/1_home/home.de.txt field blocks block abc type sectionhero',
        );
        $caller = Comments::caller('site/templates/default.php', 95, 'app');

        $openRe = '/^\s*<([^>]+)>\s+(\S+):(\d+)\s+\((app|vendor|external)\)(?:\s+\|\s+(.+?))?\s*$/';
        $callerRe = '/^\s*@caller\s+(\S+):(\d+)\s+\((app|vendor|external)\)\s*$/';

        $openText = trim($open, "<!--> \t");
        // Comments wrap the grammar in an HTML comment. The toolbar reads nodeValue.
        $openValue = $this->nodeValue($open);
        $callerValue = $this->nodeValue($caller);

        self::assertSame(1, preg_match($openRe, $openValue, $openMatch));
        self::assertSame('snippet:blocks/sectionhero', $openMatch[1]);
        self::assertSame('site/snippets/blocks/sectionhero.php', $openMatch[2]);
        self::assertSame('1', $openMatch[3]);
        self::assertStringContainsString('block abc', $openMatch[5]);

        self::assertSame(1, preg_match($callerRe, $callerValue, $callerMatch));
        self::assertSame('site/templates/default.php', $callerMatch[1]);
        self::assertSame('95', $callerMatch[2]);
        self::assertNotSame('', $openText);
    }

    private function nodeValue(string $comment): string
    {
        self::assertSame(1, preg_match('/^<!--\s*(.*?)\s*-->$/s', $comment, $match));

        return $match[1];
    }
}
