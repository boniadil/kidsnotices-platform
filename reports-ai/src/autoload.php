<?php

declare(strict_types=1);

// Loads the classes of src/ (namespace ReportsAI\). No Composer and no external library needed.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ReportsAI\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
