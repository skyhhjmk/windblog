<?php

namespace support\exception;

use app\exception\BaseException;
use Throwable;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;

class Handler extends ExceptionHandler
{
    public $dontReport = [
        BaseException::class,
    ];

    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    public function render(Request $request, Throwable $exception): Response
    {
        if ($request->expectsJson() || $request->isAjax()) {
            $code = $exception->getCode();
            $msg = $exception->getMessage();
            $data = $exception instanceof BaseException ? $exception->getData() : [];

            // Default to 500 if code is 0 or invalid for HTTP status
            $status = ($code >= 400 && $code < 600) ? $code : 500;

            return json([
                'code' => $code ?: 500,
                'msg' => $msg ?: 'Server Error',
                'data' => $data,
                'trace' => config('app.debug') ? $exception->getTrace() : [],
            ], $status);
        }

        return parent::render($request, $exception);
    }
}
