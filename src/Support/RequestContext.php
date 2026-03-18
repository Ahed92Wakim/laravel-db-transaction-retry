<?php

namespace DatabaseTransactions\RetryHelper\Support;

use Throwable;

class RequestContext
{
    /**
     * Build a standardized snapshot of the current HTTP request context.
     *
     * @return array{
     *     method: string|null,
     *     route_name: string|null,
     *     url: string|null,
     *     ip_address: string|null,
     *     user_id: string|int|null,
     *     user_type: string|null,
     *     auth_header_len: int|null,
     *     auth_header_hash: string|null
     * }
     */
    public static function snapshot(): array
    {
        $data = [
            'method'           => null,
            'route_name'       => null,
            'url'              => null,
            'ip_address'       => null,
            'user_id'          => null,
            'user_type'        => null,
            'auth_header_len'  => null,
            'auth_header_hash' => null,
        ];

        if (! function_exists('request') || ! function_exists('app') || ! app()->bound('request')) {
            return $data;
        }

        try {
            $request = request();

            if (method_exists($request, 'getMethod')) {
                $data['method'] = $request->getMethod();
            }

            if (method_exists($request, 'route')) {
                $route = $request->route();

                if (is_object($route) && method_exists($route, 'uri')) {
                    $data['url'] = $route->uri();
                }

                if (is_object($route) && method_exists($route, 'getName')) {
                    $data['route_name'] = $route->getName();
                } elseif (is_string($route)) {
                    $data['route_name'] = $route;
                }
            }

            if (method_exists($request, 'ip')) {
                $data['ip_address'] = $request->ip();
            }

            if (method_exists($request, 'header')) {
                $auth = $request->header('authorization');

                if (is_string($auth) && $auth !== '') {
                    $data['auth_header_len']  = strlen($auth);
                    $data['auth_header_hash'] = hash('sha256', $auth);
                }
            }

            if (method_exists($request, 'user')) {
                $user = $request->user();

                if (is_object($user)) {
                    $data['user_type'] = get_class($user);

                    if (method_exists($user, 'getAuthIdentifier')) {
                        $id              = $user->getAuthIdentifier();
                        $data['user_id'] = (is_scalar($id) || (is_object($id) && method_exists($id, '__toString'))) ? (string) $id : null;
                    } elseif (isset($user->id)) {
                        $id              = $user->id;
                        $data['user_id'] = (is_scalar($id) || (is_object($id) && method_exists($id, '__toString'))) ? (string) $id : null;
                    }
                }
            }
        } catch (Throwable) {
            // Silently ignore context retrieval errors.
        }

        return $data;
    }
}
