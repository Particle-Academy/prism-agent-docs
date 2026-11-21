<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Pictures;

use Prism\AgentDocs\Support\DocumentTree;

/**
 * Names every picture in a document so the writer can place it by name.
 *
 * last-word's named images (0.21.0) let a host register pictures and the
 * model write `{"type": "image", "src": "image:NAME"}` instead of carrying the
 * bytes. That is only safe if the host registers EVERY picture the model can
 * see. G-316 scenario 1: the host named only the body's pictures, the logo and
 * divider lived in the page header, and the writer answered `image_unknown`
 * thirteen times before an empty document was saved.
 *
 * So this walks the whole document (body, every band, footnotes, cells, a page
 * break's bands) through DocumentTree, which does not enumerate places, and
 * names each distinct picture once. Two identical pictures share a name.
 */
final class PictureRegistry
{
    public const string PREFIX = 'picture-';

    private const string DATA_URL = '#^data:image/(png|jpe?g);base64,(.*)$#sD';

    /**
     * @param  array<string, mixed>  $doc  a document as Agent::read() returns it, pictures as data URLs
     */
    public static function register(array $doc): RegisteredPictures
    {
        /** @var array<string, string> $byHash sha256 => name */
        $byHash = [];
        /** @var array<string, string> $images name => bytes */
        $images = [];
        /** @var list<Picture> $pictures */
        $pictures = [];

        $named = DocumentTree::mapImages($doc, static function (string $src, string $part, string $path) use (&$byHash, &$images, &$pictures): string {
            $bytes = self::decode($src);
            if ($bytes === null) {
                return $src;
            }
            $hash = hash('sha256', $bytes);
            $name = $byHash[$hash] ??= self::PREFIX.(count($byHash) + 1);
            $images[$name] = $bytes;
            $pictures[] = new Picture($name, $part, $path, $hash, strlen($bytes), self::type($bytes));

            return 'image:'.$name;
        });

        return new RegisteredPictures($named, $images, $pictures);
    }

    /** The bytes of a PNG or JPEG data URL, or null when $src is not one. */
    public static function decode(string $src): ?string
    {
        if (preg_match(self::DATA_URL, $src, $m) !== 1) {
            return null;
        }
        $bytes = base64_decode($m[2], true);

        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    private static function type(string $bytes): string
    {
        return str_starts_with($bytes, "\x89PNG") ? 'png' : 'jpeg';
    }
}
