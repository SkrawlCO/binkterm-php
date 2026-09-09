<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Thrown when a declarative navigation definition is structurally invalid in a
 * way that prevents an in-memory model from being built at all (as opposed to a
 * recoverable {@see ValidationError}, which the loader collects and reports).
 */
class NavigationSchemaException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $path = '')
    {
        parent::__construct($path === '' ? $message : "{$path}: {$message}");
    }
}
