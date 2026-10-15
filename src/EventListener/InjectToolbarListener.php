<?php

declare(strict_types=1);

namespace Doska\EventListener;

use Doska\Http\ControllerLocation;
use Doska\Twig\Comments;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Adds controller boundary comments and the toolbar element to debug HTML.
 *
 * The toolbar script is only injected into a full document. Fragment and
 * sub-request HTML still receives the controller comment, so a click inside
 * an embedded controller resolves to that action.
 */
final class InjectToolbarListener
{
    /**
     * @param list<string> $excludePaths
     * @param array{top: int|null, right: int|null, bottom: int|null, left: int|null} $position
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly bool $enabled,
        private readonly string $projectDir,
        private readonly bool $allowRemoteAccess,
        private readonly array $excludePaths,
        private readonly array $position,
        private readonly string $dock = 'bottom',
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->enabled || !$this->isLocal($event->getRequest())) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$this->isInjectableHtml($request, $response) || $this->isExcluded($request)) {
            return;
        }

        $content = $response->getContent();
        if (false === $content || '' === $content) {
            return;
        }

        $location = ControllerLocation::fromRequest($request, $this->projectDir);
        if (null !== $location) {
            $content = $this->wrapController($content, $location, $event->isMainRequest());
        }

        if ($event->isMainRequest() && $this->acceptsToolbar($request, $response)) {
            $injected = $this->injectToolbar($content, $location);
            if (null !== $injected) {
                $content = $injected;
            }
        }

        $response->setContent($content);
        $response->headers->remove('Content-Length');
    }

    private function isLocal(Request $request): bool
    {
        if ($this->allowRemoteAccess) {
            return true;
        }

        return in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true);
    }

    private function isExcluded(Request $request): bool
    {
        $path = $request->getPathInfo();
        foreach ($this->excludePaths as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isInjectableHtml(Request $request, Response $response): bool
    {
        if ($response->isRedirection()) {
            return false;
        }

        $disposition = strtolower(trim(explode(';', (string) $response->headers->get('Content-Disposition', ''))[0]));
        if ('attachment' === $disposition) {
            return false;
        }

        $contentType = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type', ''))[0]));

        return 'text/html' === $contentType;
    }

    private function acceptsToolbar(Request $request, Response $response): bool
    {
        if ($request->isXmlHttpRequest() || $request->headers->has('Turbo-Frame')) {
            return false;
        }

        return 'html' === $request->getRequestFormat();
    }

    /**
     * @param array{controller: string, file: string, line: int, route: ?string, scope: string} $location
     */
    private function wrapController(string $content, array $location, bool $mainRequest): string
    {
        $extra = $location['controller'];
        if (null !== $location['route']) {
            $extra .= ' route:'.$location['route'];
        }
        $open = Comments::open('controller', $location['file'], $location['line'], $location['scope'], $extra);
        $close = Comments::close('controller');

        if ($mainRequest && false !== $body = $this->findBody($content)) {
            [$openAt, $closeAt] = $body;
            $content = substr_replace($content, "\n".$close."\n", $closeAt, 0);
            $content = substr_replace($content, "\n".$open."\n", $openAt, 0);

            return $content;
        }

        return $open."\n".$content."\n".$close;
    }

    /**
     * @param array{controller: string, file: string, line: int, route: ?string, scope: string}|null $location
     */
    private function injectToolbar(string $content, ?array $location): ?string
    {
        if (str_contains($content, 'id="doska-toolbar"')) {
            return $content;
        }

        $body = $this->findBody($content);
        if (null === $body) {
            return null;
        }

        try {
            $scriptUrl = $this->urlGenerator->generate('_doska_toolbar');
        } catch (RouteNotFoundException) {
            return $content;
        }

        [$openAt, $closeAt] = $body;
        $config = [
            'controller' => $location['controller'] ?? null,
            'source' => null !== $location ? $location['file'].':'.$location['line'] : null,
            'route' => $location['route'] ?? null,
            'root' => $this->projectDir,
            'dock' => $this->dock,
            'position' => $this->position,
        ];
        $configAttr = htmlspecialchars(
            json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $src = htmlspecialchars($scriptUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $element = "\n".'<doska-toolbar id="doska-toolbar" data-turbo-permanent data-config="'.$configAttr.'"></doska-toolbar>'."\n";
        $script = "\n".'<script type="module" id="doska-toolbar-script" src="'.$src.'"></script>'."\n";

        if (!str_contains($content, 'id="doska-toolbar-script"')) {
            $content = substr_replace($content, $script, $openAt, 0);
            $closeAt += strlen($script);
        }

        return substr_replace($content, $element, $closeAt, 0);
    }

    /**
     * @return array{int, int}|null byte offsets of the end of the opening body tag and the start of the closing body tag
     */
    private function findBody(string $html): ?array
    {
        if (!preg_match('/<body\b[^>]*>/i', $html, $open, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $openAt = $open[0][1] + strlen($open[0][0]);
        $closeAt = strripos($html, '</body>');
        if (false === $closeAt || $closeAt < $openAt) {
            return null;
        }

        return [$openAt, $closeAt];
    }
}
