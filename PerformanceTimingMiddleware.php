<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PerformanceTimingMiddleware
{
	private const SLOW_REQUEST_MS = 500;

	public function handle(Request $request, Closure $next): Response
	{
		$startedAt = hrtime(true);
		$response = null;

		try {
			$response = $next($request);
			return $response;
		} finally {
			$durationMs = (int) round((hrtime(true) - $startedAt) / 1000000);
			if ($durationMs >= self::SLOW_REQUEST_MS) {
				Log::warning('Slow gallery API request.', [
					'method' => $request->method(),
					'route' => $this->routeTemplate($request),
					'status' => $response?->getStatusCode() ?? 500,
					'duration_ms' => $durationMs,
				]);
			}
		}
	}

	private function routeTemplate(Request $request): string
	{
		$route = $request->route();
		if (is_object($route) && method_exists($route, 'uri')) {
			return $route->uri();
		}

		return 'api/v2';
	}

}
