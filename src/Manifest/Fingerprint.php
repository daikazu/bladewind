<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Manifest;

final readonly class Fingerprint
{
    public function __construct(
        public string $content,
        public int $size,
        public int $mtime,
    ) {}

    public static function fromSource(string $source, string $path): self
    {
        if (is_file($path)) {
            $filesize = filesize($path);
            $size = $filesize !== false ? $filesize : strlen($source);

            $filemtime = filemtime($path);
            $mtime = $filemtime !== false ? $filemtime : 0;
        } else {
            $size = strlen($source);
            $mtime = 0;
        }

        return new self(hash('xxh128', $source), $size, $mtime);
    }

    /**
     * @return array{content: string, size: int, mtime: int}
     */
    public function toArray(): array
    {
        return ['content' => $this->content, 'size' => $this->size, 'mtime' => $this->mtime];
    }

    /**
     * @param  array{content: string, size: int, mtime: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['content'], $data['size'], $data['mtime']);
    }
}
