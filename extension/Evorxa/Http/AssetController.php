<?php

namespace Paymenter\Extensions\Servers\Evorxa\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Paymenter\Extensions\Servers\Evorxa\Services\Panel;

/**
 * Serves the panel's CSS, JS and logos from resources/assets (the extension folder is not public).
 * Only whitelisted file types inside that folder; versioned URLs are cached for a year.
 */
class AssetController extends Controller
{
    const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __invoke(Request $request, $path)
    {
        $path = (string) $path;
        if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_.-]+)*\.(css|js|svg|png|webp)$#i', $path) || strpos($path, '..') !== false) {
            abort(404);
        }
        $base = realpath(Panel::assetsDir());
        $file = realpath($base . '/' . $path);
        if (!$base || !$file || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
            abort(404);
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $cache = $request->query('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=3600';
        return response()->file($file, [
            'Content-Type' => self::TYPES[$ext],
            'Cache-Control' => $cache,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
