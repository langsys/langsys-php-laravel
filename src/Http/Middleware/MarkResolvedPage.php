<?php

namespace Langsys\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Langsys\Laravel\Http\ResponseKind;
use Langsys\SDK\Client;
use Langsys\SDK\Locale\LocaleDetector;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GATE-10's producer for a page Laravel renders (FRM-4): its text was translated by `__()` with no
 * page walk, so its root says so, and a browser SDK on the page never reads that text as source.
 * When to mark and what to write are the core's (`Client::markResolved()`): a page in the base
 * locale stays source, a root already marked keeps its marker, and nothing else in the page changes.
 *
 * Only a request that built a client can hold translated text, so one that built none is left
 * alone — an app with no credentials is never touched.
 */
class MarkResolvedPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!app()->resolved(Client::class) || !self::_isPage($response) || ResponseKind::of($request) !== ResponseKind::SERVER) {
            return $response;
        }

        $html = $response->getContent();

        if (is_string($html) && $html !== '') {
            $response->setContent(app(Client::class)->markResolved($html, LocaleDetector::normalize(app()->getLocale())));
        }

        return $response;
    }

    private static function _isPage(Response $response): bool
    {
        if ($response instanceof JsonResponse || $response instanceof RedirectResponse
            || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        $type = (string) $response->headers->get('Content-Type');

        return $type === '' || str_contains($type, 'text/html');
    }
}
