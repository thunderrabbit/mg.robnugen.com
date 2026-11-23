<?php

declare(strict_types=1);

namespace Issues\Images;

/**
 * The image store's directory on disk. Everything under it is private to the
 * web user (directories 0700, files 0600), a file is only ever added whole,
 * and nothing is overwritten.
 */
final readonly class ImageFiles
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function absolute(ImagePath $path): string
    {
        return $this->root . '/' . $path->relative();
    }

    public function exists(ImagePath $path): bool
    {
        return is_file($this->absolute($path));
    }

    /**
     * Copies a file to its home. The source is left where it was.
     *
     * @throws \RuntimeException when something is already there, or the copy fails
     */
    public function put(string $sourcePath, ImagePath $path): void
    {
        $target = $this->absolute($path);
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory}");
        }
        if (file_exists($target)) {
            throw new \RuntimeException("{$path->relative()} is already there");
        }

        // Written under another name first, so a half-copied file never has a
        // picture's name. The dot keeps it from ever matching a picture URL.
        $partial = $directory . '/.partial-' . bin2hex(random_bytes(8));
        try {
            if (!@copy($sourcePath, $partial) || !chmod($partial, 0600)) {
                throw new \RuntimeException("Cannot store {$path->relative()}");
            }
            // link() fails if the target appeared meanwhile; rename() would replace it.
            if (!@link($partial, $target)) {
                throw new \RuntimeException("{$path->relative()} is already there");
            }
        } finally {
            if (file_exists($partial)) {
                unlink($partial);
            }
        }
    }

    public function delete(ImagePath $path): void
    {
        $target = $this->absolute($path);
        if (is_file($target)) {
            unlink($target);
        }
    }
}
