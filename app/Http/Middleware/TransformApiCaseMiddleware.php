<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TransformApiCaseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->transformRequestKeysToSnakeCase($request);

        $response = $next($request);

        return $this->transformResponseKeysToCamelCase($response);
    }

    private function transformRequestKeysToSnakeCase(Request $request): void
    {
        $request->query->replace($this->transformKeys($request->query->all(), static fn(string $key): string => Str::snake($key)));

        if ($request->request->count() > 0) {
            $request->request->replace($this->transformKeys($request->request->all(), static fn(string $key): string => Str::snake($key)));
        }

        if ($request->isJson()) {
            $json = $request->json()->all();

            if (is_array($json)) {
                $snakeCaseJson = $this->transformKeys($json, static fn(string $key): string => Str::snake($key));
                $request->json()->replace($snakeCaseJson);
                $request->replace($snakeCaseJson);
            }
        }
    }

    private function transformResponseKeysToCamelCase(Response $response): Response
    {
        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);

        if (! is_array($data)) {
            return $response;
        }

        $response->setData($this->transformKeys($data, static fn(string $key): string => Str::camel($key)));

        return $response;
    }

    private function transformKeys(mixed $value, callable $keyTransformer): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $transformed = [];

        foreach ($value as $key => $item) {
            $transformedKey = is_string($key) ? $keyTransformer($key) : $key;

            if (is_string($transformedKey) && array_key_exists($transformedKey, $transformed)) {
                $transformed[$key] = $this->transformKeys($item, $keyTransformer);

                continue;
            }

            $transformed[$transformedKey] = $this->transformKeys($item, $keyTransformer);
        }

        return $transformed;
    }
}