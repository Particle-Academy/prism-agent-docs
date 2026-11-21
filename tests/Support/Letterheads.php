<?php

declare(strict_types=1);

namespace Tests\Support;

use LastWord\Agent;
use Prism\AgentDocs\Pictures\PictureRegistry;
use Prism\AgentDocs\Support\DocumentTree;

/**
 * The G-316 lab fixtures, built from tiny PNGs. No customer file: the owner's
 * letterhead is not shared, so each fixture reproduces only the STRUCTURE
 * Moic reported (where the pictures sit, what the margins are).
 */
final class Letterheads
{
    /** A solid-colour PNG, written byte by byte so the fixture needs no image library. */
    public static function png(int $width, int $height, array $rgb): string
    {
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0".str_repeat(chr($rgb[0]).chr($rgb[1]).chr($rgb[2]), $width);
        }
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($raw))
            .$chunk('IEND', '');
    }

    public static function logo(): string
    {
        return self::png(8, 4, [200, 30, 30]);
    }

    public static function divider(): string
    {
        return self::png(576, 5, [20, 40, 120]);
    }

    public static function dataUrl(string $png): string
    {
        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * Scenario 1: `blocks: []`; the header holds the logo and the 576x5
     * divider; the footer is a centred page number.
     */
    public static function headerLetterhead(): string
    {
        return Agent::toBytes([
            'blocks' => [],
            'page' => [
                'header' => ['blocks' => [
                    ['type' => 'image', 'src' => self::dataUrl(self::logo()), 'widthPx' => 96, 'heightPx' => 48],
                    ['type' => 'image', 'src' => self::dataUrl(self::divider())],
                ]],
                'footer' => ['center' => '{page}'],
            ],
        ]);
    }

    /** Scenario 1's letterhead with a body, for the whole-rewrite owner rule. */
    public static function headerLetterheadWithBody(): string
    {
        return Agent::toBytes([
            'blocks' => [
                ['type' => 'paragraph', 'runs' => [['text' => 'Dear Ms Example,']]],
                ['type' => 'paragraph', 'runs' => [['text' => 'Thank you for your letter of 3 November.']]],
            ],
            'page' => [
                'header' => ['blocks' => [
                    ['type' => 'image', 'src' => self::dataUrl(self::logo()), 'widthPx' => 96, 'heightPx' => 48],
                    ['type' => 'image', 'src' => self::dataUrl(self::divider())],
                ]],
                'footer' => ['center' => '{page}'],
            ],
        ]);
    }

    /**
     * Scenario 3: a body-designed letterhead. A two-column table (logo left;
     * "Prepared for / Prepared by / Date" right), a divider picture, a title
     * and a paragraph; one-inch margins; no page header.
     */
    public static function bodyLetterhead(): string
    {
        $cell = static fn (string $text): array => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => $text]]]]];

        return Agent::toBytes([
            'blocks' => [
                ['type' => 'table', 'rows' => [
                    ['cells' => [
                        ['blocks' => [['type' => 'image', 'src' => self::dataUrl(self::logo()), 'widthPx' => 96, 'heightPx' => 48]]],
                        ['blocks' => [
                            ['type' => 'paragraph', 'runs' => [['text' => 'Prepared for: Example Ltd']]],
                            ['type' => 'paragraph', 'runs' => [['text' => 'Prepared by: Office of the CFO']]],
                            ['type' => 'paragraph', 'runs' => [['text' => 'Date: 21 November 2026']]],
                        ]],
                    ]],
                ]],
                ['type' => 'image', 'src' => self::dataUrl(self::divider())],
                ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Proposal']]],
                ['type' => 'paragraph', 'runs' => [['text' => 'This proposal sets out the work and its cost.']]],
            ],
            'page' => ['margins' => ['top' => 72, 'right' => 72, 'bottom' => 72, 'left' => 72]],
        ]);
    }

    public static function emptyDraft(): string
    {
        return Agent::toBytes(['blocks' => []]);
    }

    /**
     * Every picture in a file, by part, as sha256 of the bytes.
     *
     * @return array<string, list<string>>
     */
    public static function picturesByPart(string $docx): array
    {
        $out = [];
        foreach (DocumentTree::images(Agent::read($docx)) as $image) {
            $bytes = PictureRegistry::decode($image['src']);
            $out[$image['part']][] = hash('sha256', (string) $bytes);
        }

        return $out;
    }
}
