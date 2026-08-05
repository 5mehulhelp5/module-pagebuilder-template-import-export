<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\Model;

/**
 * Validates relative file paths used by template import and export so a
 * crafted path cannot escape its intended base directory (CWE-22).
 */
class PathValidator
{
    /**
     * Whether the path contains a NUL byte or a ".." path component.
     *
     * Components are checked after normalizing backslashes to forward slashes.
     *
     * @param string $path
     * @return bool
     */
    public function hasTraversal(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return true;
        }
        $normalized = str_replace('\\', '/', $path);

        return in_array('..', explode('/', $normalized), true);
    }

    /**
     * Whether the path is a plain relative path safe to join to a base directory.
     *
     * Requires a non-empty path with no traversal, no absolute prefix, and no
     * scheme ("phar://") or drive-letter ("C:") prefix.
     *
     * @param string $path
     * @return bool
     */
    public function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || $this->hasTraversal($path)) {
            return false;
        }
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, '/')) {
            return false;
        }

        return preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $normalized) !== 1;
    }
}
