<?php
// Unit test bootstrap - load project classes

$projectRoot = dirname(__DIR__, 2);

// Simple autoloader that doesn't throw on missing classes
spl_autoload_register(function ($class) use ($projectRoot) {
    // Convert namespace to path
    $class = ltrim($class, '\\');
    $path = str_replace(['\\', '_'], DIRECTORY_SEPARATOR, $class);
    $file = $projectRoot . '/classes/' . $path . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
    // Return silently if file not found (let other autoloaders handle it)
});
