<?php

namespace app\exception;

use Exception;
use Throwable;

class BaseException extends Exception
{
    protected $data = [];

    public function __construct(string $message = '', int $code = 0, Throwable $previous = null, array $data = [])
    {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
