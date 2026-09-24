<?php

declare(strict_types=1);

if (! function_exists('brotli_compress')) {
    /**
     * Signature stub for ext-brotli so static analysis knows the function when the extension is absent.
     */
    function brotli_compress(string $data, int $quality = 11, int $mode = 0): string|false
    {
        return false;
    }
}
