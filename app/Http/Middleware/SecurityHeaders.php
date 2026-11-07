<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof Response) {
            return $response;
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', $this->permissionsPolicy());
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        if ($request->isSecure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        $response->headers->remove('X-Powered-By');

        return $response;
    }

    /**
     * The QR scanner is the primary way a guest gets through the gate, so the
     * camera has to be permitted. It is granted to 'self' only, which means
     * no third-party origin can open a camera through this app.
     *
     * Everything this application has no use for stays switched off.
     */
    private function permissionsPolicy(): string
    {
        return implode(', ', [
            'camera=(self)',
            'geolocation=()',
            'microphone=()',
            'payment=()',
            'usb=()',
            'interest-cohort=()',
        ]);
    }

    private function contentSecurityPolicy(): string
    {
        $viteOrigins = $this->viteDevOrigins();

        $directives = [
            "default-src 'self'",
            'script-src ' . $this->directiveSources($viteOrigins),
            'style-src ' . $this->directiveSources($viteOrigins, "'self' 'unsafe-inline' https://fonts.googleapis.com"),
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https:",
            'connect-src ' . $this->directiveSources($viteOrigins),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        // upgrade-insecure-requests would rewrite http://127.0.0.1:5173 to
        // https during HMR, which breaks the Vite dev server.
        if ($viteOrigins === []) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    private function viteDevOrigins(): array
    {
        if (! Vite::isRunningHot()) {
            return [];
        }

        $url = trim((string) file_get_contents(Vite::hotFile()));

        if ($url === '') {
            return [];
        }

        return collect([$this->originFromUrl($url), $this->websocketOriginFromUrl($url)])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function originFromUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (empty($parts['host'])) {
            return null;
        }

        $scheme = ($parts['scheme'] ?? 'http') === 'https' ? 'https' : 'http';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $parts['host'] . $port;
    }

    private function websocketOriginFromUrl(string $url): ?string
    {
        $origin = $this->originFromUrl($url);

        if ($origin === null) {
            return null;
        }

        return str_contains($origin, 'https://')
            ? str_replace('https://', 'wss://', $origin)
            : str_replace('http://', 'ws://', $origin);
    }

    private function directiveSources(array $extra, string $builtin = "'self'"): string
    {
        return collect([$builtin, ...$extra])
            ->filter()
            ->unique()
            ->implode(' ');
    }
}
