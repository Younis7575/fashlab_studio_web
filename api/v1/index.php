<?php
/**
 * Mobile API front controller.
 *
 * Every /api/v1/* request lands here (see .htaccess). Routes are registered as
 * method + pattern, where {name} captures one path segment.
 */

declare(strict_types=1);

// Buffer all output so that stray warnings or notices cannot corrupt the JSON
// body or trigger "headers already sent" on shared hosting environments.
ob_start();

require_once __DIR__ . '/bootstrap.php';

/* ---------------------------------------------------------------------------
   Router
--------------------------------------------------------------------------- */

/** @var array<int, array{method:string, pattern:string, handler:callable}> */
$GLOBALS['api_routes'] = [];

function route(string $method, string $pattern, callable $handler): void
{
    $GLOBALS['api_routes'][] = [
        'method'  => strtoupper($method),
        'pattern' => trim($pattern, '/'),
        'handler' => $handler,
    ];
}

/**
 * Resolve the path the request asked for, relative to /api/v1.
 *
 * Three call styles are accepted, because this webspace applies mod_rewrite
 * patterns but silently drops the substitution, so a rewrite alone cannot be
 * relied on (the storefront's pretty permalinks fail there for the same
 * reason). All of these reach the same route:
 *
 *   /api/v1/products                    (only where rewrites work)
 *   /api/v1/index.php/products          PATH_INFO
 *   /api/v1/index.php?_route=products   query string, works everywhere
 */
function api_request_path(): string
{
    $path = (string) ($_GET['_route'] ?? '');

    if ($path === '') {
        $path = (string) ($_SERVER['PATH_INFO'] ?? '');
    }

    if ($path === '') {
        // Split on '?' by hand rather than with parse_url(): cart keys look
        // like "92:0", and parse_url() reads the colon as a scheme separator
        // and returns false for the whole URI.
        $uri = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];

        $marker = '/api/v1/index.php';
        $pos = stripos($uri, $marker);
        if ($pos !== false) {
            $path = substr($uri, $pos + strlen($marker));
        } else {
            $pos  = stripos($uri, '/api/v1');
            $path = $pos === false ? $uri : substr($uri, $pos + 7);
        }
    }

    return trim(rawurldecode($path), '/');
}

require __DIR__ . '/routes/session.php';
require __DIR__ . '/routes/catalog.php';
require __DIR__ . '/routes/cart.php';
require __DIR__ . '/routes/wishlist.php';
require __DIR__ . '/routes/orders.php';
require __DIR__ . '/routes/content.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path   = api_request_path();
$segments = $path === '' ? [] : explode('/', $path);

$pathMatched = false;

foreach ($GLOBALS['api_routes'] as $route) {
    $routeSegments = $route['pattern'] === '' ? [] : explode('/', $route['pattern']);

    if (count($routeSegments) !== count($segments)) {
        continue;
    }

    $params = [];
    $matches = true;

    foreach ($routeSegments as $i => $segment) {
        if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
            $params[trim($segment, '{}')] = $segments[$i];
            continue;
        }
        if (strcasecmp($segment, $segments[$i]) !== 0) {
            $matches = false;
            break;
        }
    }

    if (!$matches) {
        continue;
    }

    $pathMatched = true;

    if ($route['method'] === $method) {
        $route['handler']($params);
        api_ok(null); // Handlers exit; this is only a safety net.
    }
}

if ($pathMatched) {
    api_fail('Method ' . $method . ' is not allowed on this endpoint.', 405);
}

api_fail('Endpoint not found: /api/v1/' . $path, 404);
