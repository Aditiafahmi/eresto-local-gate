<?php

namespace App\Http\Middleware\Mock;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HikvisionDigestAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            app()->environment(['local', 'testing'])
                && (bool) config('mock.hikvision.server_enabled', false),
            404
        );

        $authorization = $request->header('Authorization');

        if (is_string($authorization) && str_starts_with($authorization, 'Digest ')) {
            return $next($request);
        }

        $deviceName = (string) config(
            'mock.hikvision.device_name',
            'mock-device'
        );
        $nonce = hash('sha256', 'eresto-mock-hikvision:'.$deviceName);

        return response('', 401)->header(
            'WWW-Authenticate',
            'Digest realm="Mock Hikvision", qop="auth", nonce="'.$nonce.'", algorithm=MD5'
        );
    }
}
