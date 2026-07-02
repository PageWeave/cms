<?php

declare(strict_types=1);

// Test bootstrap: load all modular src/ files so flat global functions
// are available. PHP resolves function calls at runtime, so require order
// does not matter as long as no file executes logic at file scope (core
// is pure-function; the HTTP shell is invoked only by the compiled entry point).
foreach (glob(__DIR__ . '/../src/*.php') as $file) {
    require_once $file;
}
foreach (glob(__DIR__ . '/../src/tools/*.php') as $file) {
    require_once $file;
}
