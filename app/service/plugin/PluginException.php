<?php

namespace app\service\plugin;

use RuntimeException;

class PluginException extends RuntimeException
{
}

class PluginValidationException extends PluginException
{
    /** @var array<int, string> */
    private array $errors;

    public function __construct(array $errors)
    {
        parent::__construct('Plugin validation failed');
        $this->errors = array_values($errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class PluginDependencyException extends PluginException
{
}

class PluginConflictException extends PluginException
{
}
