<?php
declare(strict_types=1);

namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Request;
use think\Response;
use app\service\LogService;

/**
 * Global exception handler - returns structured JSON error envelope:
 * {"status":"error","code":"ERROR_CODE","message":"...","trace_id":"..."}
 */
class ExceptionHandle extends Handle
{
    /**
     * Do not report these exception types
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    public function render($request, \Throwable $e): Response
    {
        // Let HttpResponseException pass through (used by json() helper)
        if ($e instanceof HttpResponseException) {
            $response = $e->getResponse();
            $this->addTraceHeader($request, $response);
            return $response;
        }

        $traceId = \app\middleware\TraceId::getId();

        // Validation errors
        if ($e instanceof ValidateException) {
            return $this->errorResponse(400, 'VALIDATION_ERROR', $e->getMessage(), $traceId);
        }

        // Model/Data not found
        if ($e instanceof ModelNotFoundException || $e instanceof DataNotFoundException) {
            return $this->errorResponse(404, 'NOT_FOUND', 'Resource not found', $traceId);
        }

        // HTTP exceptions (404, 405, etc.)
        if ($e instanceof HttpException) {
            $statusCode = $e->getStatusCode();
            $code = $this->httpStatusToCode($statusCode);
            return $this->errorResponse($statusCode, $code, $e->getMessage() ?: 'HTTP Error', $traceId);
        }

        // Log unexpected errors
        LogService::error('unhandled_exception', [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ], $traceId);

        // Generic 500
        return $this->errorResponse(500, 'INTERNAL_ERROR', 'Internal server error', $traceId);
    }

    private function errorResponse(int $httpStatus, string $code, string $message, string $traceId): Response
    {
        $data = [
            'status'   => 'error',
            'code'     => $code,
            'message'  => $message,
            'trace_id' => $traceId,
        ];

        $response = json($data, $httpStatus);
        $response->header(['X-Trace-Id' => $traceId]);
        return $response;
    }

    private function addTraceHeader(Request $request, Response $response): void
    {
        $traceId = \app\middleware\TraceId::getId();
        if ($traceId) {
            $response->header(['X-Trace-Id' => $traceId]);
        }
    }

    private function httpStatusToCode(int $status): string
    {
        return match ($status) {
            400 => 'VALIDATION_ERROR',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            413 => 'PAYLOAD_TOO_LARGE',
            423 => 'LOCKED',
            429 => 'RATE_LIMITED',
            default => 'INTERNAL_ERROR',
        };
    }
}
