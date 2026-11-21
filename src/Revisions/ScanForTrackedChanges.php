<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Revisions;

use InvalidArgumentException;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * The default TrackedChangesGuard: open the .docx and look for
 * WordprocessingML revision elements, until last-word reports them itself.
 *
 * It reads EVERY `.xml` entry in the archive, not a list of part names: the
 * document, each header and footer, the footnotes, the styles. A list is a
 * guess about which parts a reader opens, and a part left off it is a place a
 * tracked change goes unseen. An element counts by its NAMESPACE, never by its
 * `w:` prefix, which a file may bind to any name.
 *
 * Bounded, and closed on doubt. The archive, its entry count, each XML part's
 * inflated size (read with an explicit length, so a forged size in the zip
 * header allocates nothing), their total and their compression ratio are all
 * capped. A part with a DOCTYPE is not parsed further (a .docx part never has
 * one, and entities are never expanded); a part that is not well-formed is not
 * guessed at. Each of those is uncheckable, which the agent refuses like a
 * tracked change: "could not tell" is not "none".
 */
final readonly class ScanForTrackedChanges implements TrackedChangesGuard
{
    /** last-word's own archive cap. */
    public const int MAX_ARCHIVE_BYTES = 64 * 1024 * 1024;

    public const int MAX_ENTRIES = 2048;

    public const int MAX_PART_BYTES = 32 * 1024 * 1024;

    public const int MAX_TOTAL_BYTES = 96 * 1024 * 1024;

    /** Inflated bytes per compressed byte; deflate tops out near 1032. Applies to parts over RATIO_FLOOR_BYTES. */
    public const int MAX_COMPRESSION_RATIO = 200;

    public const int RATIO_FLOOR_BYTES = 1024 * 1024;

    /** The WordprocessingML main namespace, transitional and strict. */
    public const array NAMESPACES = [
        'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'http://purl.oclc.org/ooxml/wordprocessingml/main',
    ];

    /**
     * last-word reads .odt as well, and its reader drops tracked deletions the
     * same way, so an OpenDocument change region counts too. Not the
     * `tracked-changes` wrapper: an editor may write it empty.
     */
    public const string ODF_TEXT_NAMESPACE = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    public const array ODF_ELEMENTS = ['changed-region', 'change-start', 'change-end', 'change'];

    /**
     * The revision elements (ECMA-376 Part 1, 17.13.5, and the move and
     * custom-XML range marks of 17.13.5.x). Any one of them, anywhere, means
     * the file holds a change someone has not accepted or rejected.
     */
    public const array ELEMENTS = [
        'ins', 'del', 'moveFrom', 'moveTo',
        'moveFromRangeStart', 'moveFromRangeEnd', 'moveToRangeStart', 'moveToRangeEnd',
        'delText', 'delInstrText',
        'rPrChange', 'pPrChange', 'sectPrChange', 'numberingChange',
        'tblPrChange', 'tblPrExChange', 'tblGridChange', 'trPrChange', 'tcPrChange',
        'cellIns', 'cellDel', 'cellMerge',
        'customXmlInsRangeStart', 'customXmlInsRangeEnd', 'customXmlDelRangeStart', 'customXmlDelRangeEnd',
        'customXmlMoveFromRangeStart', 'customXmlMoveFromRangeEnd', 'customXmlMoveToRangeStart', 'customXmlMoveToRangeEnd',
    ];

    public function __construct(
        private int $maxArchiveBytes = self::MAX_ARCHIVE_BYTES,
        private int $maxEntries = self::MAX_ENTRIES,
        private int $maxPartBytes = self::MAX_PART_BYTES,
        private int $maxTotalBytes = self::MAX_TOTAL_BYTES,
        private int $maxCompressionRatio = self::MAX_COMPRESSION_RATIO,
    ) {
        if (min($maxArchiveBytes, $maxEntries, $maxPartBytes, $maxTotalBytes, $maxCompressionRatio) < 1) {
            throw new InvalidArgumentException('Every tracked-changes scan bound must be at least 1.');
        }
    }

    public function inspect(string $docx): Inspection
    {
        if (strlen($docx) > $this->maxArchiveBytes) {
            return Inspection::uncheckable('archive_too_large');
        }

        $path = tempnam(sys_get_temp_dir(), 'agent_docs_scan_');
        if ($path === false) {
            return Inspection::uncheckable('io');
        }
        $zip = new ZipArchive;
        $opened = false;
        try {
            if (file_put_contents($path, $docx) !== strlen($docx)) {
                return Inspection::uncheckable('io');
            }
            if (@$zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                return Inspection::notAnArchive();
            }
            $opened = true;

            return $this->scan($zip);
        } catch (Throwable) {
            return Inspection::uncheckable('scan_failed');
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }

    private function scan(ZipArchive $zip): Inspection
    {
        if ($zip->numFiles > $this->maxEntries) {
            return Inspection::uncheckable('too_many_entries');
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                return Inspection::uncheckable('unreadable_entry');
            }
            $name = str_replace('\\', '/', (string) $stat['name']);
            if (! str_ends_with(strtolower($name), '.xml')) {
                continue;
            }

            // One byte past the cap: a part that fills it is over, whatever its header claims.
            $xml = @$zip->getFromIndex($i, $this->maxPartBytes + 1);
            if (! is_string($xml)) {
                return Inspection::uncheckable('unreadable_part');
            }
            $size = strlen($xml);
            if ($size > $this->maxPartBytes) {
                return Inspection::uncheckable('part_too_large');
            }
            $total += $size;
            if ($total > $this->maxTotalBytes) {
                return Inspection::uncheckable('too_much_to_check');
            }
            if ($size > self::RATIO_FLOOR_BYTES && $size > $this->maxCompressionRatio * max(1, (int) $stat['comp_size'])) {
                return Inspection::uncheckable('compression_ratio');
            }

            $found = $this->revisionIn($xml);
            if ($found === false) {
                return Inspection::uncheckable('unparsable_part');
            }
            if ($found !== null) {
                return Inspection::tracked($found, $name);
            }
        }

        return Inspection::clean();
    }

    /** The first revision element's local name, null for none, false when the part cannot be scanned. */
    private function revisionIn(string $xml): string|false|null
    {
        if ($xml === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;
        try {
            // No LIBXML_NOENT and no DTD loading: entities are never expanded,
            // and nothing is fetched.
            if (! @$reader->XML($xml, null, LIBXML_NONET)) {
                return false;
            }
            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    return false;
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if (in_array($reader->localName, self::ELEMENTS, true) && in_array($reader->namespaceURI, self::NAMESPACES, true)) {
                    return $reader->localName;
                }
                if ($reader->namespaceURI === self::ODF_TEXT_NAMESPACE && in_array($reader->localName, self::ODF_ELEMENTS, true)) {
                    return $reader->localName;
                }
            }

            return libxml_get_errors() === [] ? null : false;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
