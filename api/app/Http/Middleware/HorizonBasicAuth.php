<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * V61: gates the Horizon dashboard for a Sanctum-only app (no session-based
 * web login exists to check a Gate against). Fails closed — unset admin
 * credentials deny everyone, never silently grant access.
 */
class HorizonBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = config('horizon.basic_auth.username');
        $password = config('horizon.basic_auth.password');

        if ($username === null || $password === null || $username === '' || $password === '') {
            abort(403, 'Horizon dashboard credentials are not configured.');
        }

        // Parsed from the raw header rather than $request->getUser()/
        // getPassword() — those depend on the PHP SAPI translating the
        // Authorization header into PHP_AUTH_USER/PHP_AUTH_PW server vars,
        // which doesn't happen under every FPM/proxy config (or in tests).
        [$providedUser, $providedPassword] = $this->parseBasicAuthHeader($request);

        if (hash_equals($username, (string) $providedUser) && hash_equals($password, (string) $providedPassword)) {
            return $next($request);
        }

        return response('Unauthorized.', 401, ['WWW-Authenticate' => 'Basic realm="Horizon"']);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function parseBasicAuthHeader(Request $request): array
    {
        $header = $request->headers->get('Authorization', '');

        if (! str_starts_with($header, 'Basic ')) {
            return [null, null];
        }

        $decoded = base64_decode(substr($header, 6), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return [null, null];
        }

        return explode(':', $decoded, 2);
    }
}
