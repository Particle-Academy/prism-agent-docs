<?php

declare(strict_types=1);

use LastWord\Agent;
use Prism\AgentDocs\DocsResult;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\Outcome;
use Prism\AgentDocs\Refusal;
use Prism\AgentDocs\Revisions\Inspection;
use Prism\AgentDocs\Revisions\LastWordTrackedChanges;
use Prism\AgentDocs\Revisions\ScanForTrackedChanges;
use Prism\AgentDocs\Revisions\TrackedChangesGuard;
use Prism\AgentDocs\Tools\DocsTools;
use Prism\AgentDocs\WorkingCopy;
use Tests\Fixtures\Participant;
use Tests\Support\Letterheads;
use Tests\Support\ScriptedModel;

/*
|--------------------------------------------------------------------------
| Tracked changes are refused, never read
|--------------------------------------------------------------------------
|
| last-word 0.21's reader silently ACCEPTS tracked changes: an insertion is
| kept as plain text and a deletion is dropped. A draft built from that read
| loses every deletion a reviewer proposed, and the person is never told.
| So a working copy with any revision element, in the body, a header or a
| footer, is refused in fixed words before it is read, with no model call.
| One that cannot be checked (too big, too many parts, a DOCTYPE, not
| well-formed) is refused too: "could not tell" is not "none".
|
*/

const TC_AUTHOR = 'w:id="901" w:author="Reviewer" w:date="2026-11-21T00:00:00Z"';

/** Rewrite archive entries of a .docx. $edit gets (name, xml) and returns the new xml, or null to leave it. */
function tcRewrite(string $docx, callable $edit): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'tc_');
    file_put_contents($path, $docx);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $changed = 0;
    foreach ($names as $name) {
        $new = $edit($name, (string) $zip->getFromName($name));
        if (is_string($new)) {
            $zip->addFromString($name, $new);
            $changed++;
        }
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);
    expect($changed)->toBeGreaterThan(0, 'the fixture rewrote no part');

    return $bytes;
}

/** Put $snippet at the start of the body, or of the first header or footer part matching $part. */
function tcWithRevision(string $snippet, string $part = 'document'): string
{
    $patterns = ['document' => '#^word/document\.xml$#', 'header' => '#^word/header\d*\.xml$#', 'footer' => '#^word/footer\d*\.xml$#'];
    $done = false;

    return tcRewrite(Letterheads::headerLetterheadWithBody(), static function (string $name, string $xml) use ($snippet, $part, $patterns, &$done): ?string {
        if ($done || preg_match($patterns[$part], $name) !== 1) {
            return null;
        }
        $done = true;
        $open = $part === 'document' ? '<w:body>' : ($part === 'header' ? '<w:hdr' : '<w:ftr');
        $at = strpos($xml, $open);
        expect($at)->not->toBeFalse("{$name} has no {$open}");
        $at = (int) strpos($xml, '>', (int) $at) + 1;

        return substr($xml, 0, $at).$snippet.substr($xml, $at);
    });
}

/** A change the model would make to the letterhead's body, so a clean document reaches Ready. */
function tcRetitle(): array
{
    return [ScriptedModel::call(DocsTools::WRITE_DRAFT, [
        'document' => ['blocks' => [
            ['type' => 'paragraph', 'runs' => [['text' => 'Dear Mr Example,']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Thank you for your letter of 3 November.']]],
        ]],
    ])];
}

dataset('revision kinds', [
    'inserted text (w:ins)' => ['<w:p><w:ins '.TC_AUTHOR.'><w:r><w:t>added</w:t></w:r></w:ins></w:p>', 'ins'],
    'deleted text (w:del)' => ['<w:p><w:del '.TC_AUTHOR.'><w:r><w:delText>gone</w:delText></w:r></w:del></w:p>', 'del'],
    'moved from (w:moveFrom)' => ['<w:p><w:moveFrom '.TC_AUTHOR.'><w:r><w:t>moved</w:t></w:r></w:moveFrom></w:p>', 'moveFrom'],
    'moved to (w:moveTo)' => ['<w:p><w:moveTo '.TC_AUTHOR.'><w:r><w:t>moved</w:t></w:r></w:moveTo></w:p>', 'moveTo'],
    'a move range mark' => ['<w:p><w:moveToRangeStart '.TC_AUTHOR.' w:name="move1"/><w:r><w:t>m</w:t></w:r></w:p>', 'moveToRangeStart'],
    'run formatting (w:rPrChange)' => ['<w:p><w:r><w:rPr><w:b/><w:rPrChange '.TC_AUTHOR.'><w:rPr/></w:rPrChange></w:rPr><w:t>bold</w:t></w:r></w:p>', 'rPrChange'],
    'paragraph formatting (w:pPrChange)' => ['<w:p><w:pPr><w:jc w:val="center"/><w:pPrChange '.TC_AUTHOR.'><w:pPr/></w:pPrChange></w:pPr><w:r><w:t>c</w:t></w:r></w:p>', 'pPrChange'],
    'section (w:sectPrChange)' => ['<w:p><w:pPr><w:sectPr><w:sectPrChange '.TC_AUTHOR.'><w:sectPr/></w:sectPrChange></w:sectPr></w:pPr></w:p>', 'sectPrChange'],
    'table (w:tblPrChange)' => ['<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblPrChange '.TC_AUTHOR.'><w:tblPr/></w:tblPrChange></w:tblPr><w:tblGrid><w:gridCol w:w="2000"/></w:tblGrid><w:tr><w:tc><w:p/></w:tc></w:tr></w:tbl><w:p/>', 'tblPrChange'],
    'table row (w:trPrChange)' => ['<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="2000"/></w:tblGrid><w:tr><w:trPr><w:trPrChange '.TC_AUTHOR.'><w:trPr/></w:trPrChange></w:trPr><w:tc><w:p/></w:tc></w:tr></w:tbl><w:p/>', 'trPrChange'],
    'table cell (w:tcPrChange)' => ['<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="2000"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:tcPrChange '.TC_AUTHOR.'><w:tcPr/></w:tcPrChange></w:tcPr><w:p/></w:tc></w:tr></w:tbl><w:p/>', 'tcPrChange'],
    'inserted cell (w:cellIns)' => ['<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="2000"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:cellIns '.TC_AUTHOR.'/></w:tcPr><w:p/></w:tc></w:tr></w:tbl><w:p/>', 'cellIns'],
    'a paragraph mark inserted (w:pPr/w:rPr/w:ins)' => ['<w:p><w:pPr><w:rPr><w:ins '.TC_AUTHOR.'/></w:rPr></w:pPr><w:r><w:t>p</w:t></w:r></w:p>', 'ins'],
]);

it('refuses a document with each kind of tracked change, in fixed words, with no model call', function (string $snippet, string $element): void {
    $docx = tcWithRevision($snippet);
    $model = ScriptedModel::install([tcRetitle(), 'Done.']);

    $result = editDocument(new WorkingCopy($docx, 'v1'), 'Change the greeting to Mr Example.');

    expect($result->outcome)->toBe(Outcome::Failed)
        ->and($result->refusal)->toBe(Refusal::TrackedChanges)
        ->and($result->message)->toBe('This document has tracked changes. Accept or reject them first, then ask again.')
        ->and($result->draft)->toBeNull()
        ->and($model->requests())->toBe([]);
    expect((new ScanForTrackedChanges)->inspect($docx))->toEqual(Inspection::tracked($element, 'word/document.xml'));
})->with('revision kinds');

it('refuses a tracked change in a header and in a footer', function (string $part): void {
    $docx = tcWithRevision('<w:p><w:del '.TC_AUTHOR.'><w:r><w:delText>old address</w:delText></w:r></w:del></w:p>', $part);
    $model = ScriptedModel::install([tcRetitle(), 'Done.']);

    $result = editDocument(new WorkingCopy($docx, 'v1'), 'Change the greeting to Mr Example.');

    expect($result->refusal)->toBe(Refusal::TrackedChanges)
        ->and($model->requests())->toBe([]);
    expect((string) (new ScanForTrackedChanges)->inspect($docx)->part)->toStartWith("word/{$part}");
})->with(['header', 'footer']);

it('finds a revision by its namespace, whatever prefix the file binds it to', function (string $snippet): void {
    $result = (new ScanForTrackedChanges)->inspect(tcWithRevision($snippet));

    expect($result->status)->toBe(Inspection::TRACKED);
})->with([
    'a default namespace' => ['<p xmlns="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><ins w:id="1" w:author="R"><r><t>x</t></r></ins></p>'],
    'another prefix' => ['<rv:p xmlns:rv="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><rv:del rv:id="1" rv:author="R"/></rv:p>'],
    'the strict namespace' => ['<s:p xmlns:s="http://purl.oclc.org/ooxml/wordprocessingml/main"><s:ins s:id="1" s:author="R"/></s:p>'],
]);

it('lets a clean document through to the edit', function (): void {
    ScriptedModel::install([tcRetitle(), 'Done.']);

    $result = editDocument(new WorkingCopy(Letterheads::headerLetterheadWithBody(), 'v1'), 'Change the greeting to Mr Example.');

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and($result->refusal)->toBeNull()
        ->and($result->message)->toBe(DocsResult::READY);
});

it('does not count an element of another namespace, or the names written as plain text', function (): void {
    $other = tcWithRevision('<x:ins xmlns:x="urn:example:not-word"/>');
    $tracking = tcRewrite(Letterheads::headerLetterheadWithBody(), static fn (string $name, string $xml): ?string => $name === 'word/document.xml'
        ? str_replace('<w:body>', '<w:body><w:p><w:r><w:t>w:ins and w:del as text</w:t></w:r></w:p>', $xml)
        : null);

    expect((new ScanForTrackedChanges)->inspect($other)->status)->toBe(Inspection::CLEAN)
        ->and((new ScanForTrackedChanges)->inspect($tracking)->status)->toBe(Inspection::CLEAN);
});

it('refuses a pending draft of tracked deletions instead of passing over it to the saved file', function (): void {
    // A draft of deletions only reads as EMPTY (the reader drops them), so
    // without the guard the store default would silently revise the saved
    // file instead of the draft the person is reviewing.
    $draft = tcRewrite(Letterheads::emptyDraft(), static fn (string $name, string $xml): ?string => $name === 'word/document.xml'
        ? (string) preg_replace('#<w:body>#', '<w:body><w:p><w:del '.TC_AUTHOR.'><w:r><w:delText>the whole second paragraph</w:delText></w:r></w:del></w:p>', $xml, 1)
        : null);
    $store = new InMemoryDocumentStore(Letterheads::headerLetterheadWithBody(), $draft);
    $model = ScriptedModel::install([tcRetitle(), 'Done.']);

    $result = editDocument($store, 'Change the greeting to Mr Example.');

    expect($result->refusal)->toBe(Refusal::TrackedChanges)
        ->and($model->requests())->toBe([])
        ->and($store->draft())->toBe($draft);
});

it('refuses a zip bomb part without inflating it past the cap', function (): void {
    // 4 MiB of spaces deflates to a few KiB: a ratio no real part has.
    $bomb = tcRewrite(Letterheads::headerLetterheadWithBody(), static fn (string $name, string $xml): ?string => $name === 'word/document.xml'
        ? str_replace('<w:body>', '<w:body><!--'.str_repeat(' ', 4 * 1024 * 1024).'-->', $xml)
        : null);
    expect(strlen($bomb))->toBeLessThan(512 * 1024);
    $model = ScriptedModel::install([tcRetitle(), 'Done.']);

    $result = editDocument(new WorkingCopy($bomb, 'v1'), 'Change the greeting to Mr Example.');

    expect($result->outcome)->toBe(Outcome::Failed)
        ->and($result->refusal)->toBe(Refusal::Uncheckable)
        ->and($result->message)->toBe(DocsResult::UNCHECKABLE)
        ->and($model->requests())->toBe([]);
    expect((new ScanForTrackedChanges)->inspect($bomb)->reason)->toBe('compression_ratio');
});

it('refuses, safely, a document over each bound', function (ScanForTrackedChanges $scan, string $reason): void {
    expect($scan->inspect(Letterheads::headerLetterheadWithBody()))->toEqual(Inspection::uncheckable($reason));
})->with([
    'an oversize part' => [fn () => new ScanForTrackedChanges(maxPartBytes: 64), 'part_too_large'],
    'too many entries' => [fn () => new ScanForTrackedChanges(maxEntries: 3), 'too_many_entries'],
    'too much XML in all' => [fn () => new ScanForTrackedChanges(maxTotalBytes: 100), 'too_much_to_check'],
    'an oversize archive' => [fn () => new ScanForTrackedChanges(maxArchiveBytes: 100), 'archive_too_large'],
]);

it('refuses a part with a DOCTYPE, and never expands its entities', function (): void {
    $secret = (string) tempnam(sys_get_temp_dir(), 'tc_entity_');
    file_put_contents($secret, '<w:ins w:id="1"/>');
    $docx = tcRewrite(Letterheads::headerLetterheadWithBody(), static fn (string $name, string $xml): ?string => $name === 'word/document.xml'
        ? (string) preg_replace('#\?>#', '?><!DOCTYPE w:document [<!ENTITY x SYSTEM "file:///'.str_replace('\\', '/', $secret).'">]>', $xml, 1)
        : null);
    $model = ScriptedModel::install([tcRetitle(), 'Done.']);

    try {
        $result = editDocument(new WorkingCopy($docx, 'v1'), 'Change the greeting to Mr Example.');
    } finally {
        unlink($secret);
    }

    expect($result->refusal)->toBe(Refusal::Uncheckable)
        ->and($model->requests())->toBe([]);
});

it('refuses a part that is not well-formed rather than guessing', function (): void {
    $docx = tcRewrite(Letterheads::headerLetterheadWithBody(), static fn (string $name, string $xml): ?string => $name === 'word/document.xml'
        ? str_replace('</w:body>', '</w:bodyX>', $xml)
        : null);

    expect((new ScanForTrackedChanges)->inspect($docx))->toEqual(Inspection::uncheckable('unparsable_part'));
});

it('finds an OpenDocument change region too, since last-word reads .odt', function (): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'tc_odt_');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
    $zip->addFromString('content.xml', '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:text><text:tracked-changes><text:changed-region text:id="c1"/></text:tracked-changes></office:text></office:body></office:document-content>');
    $zip->close();
    $odt = (string) file_get_contents($path);
    unlink($path);

    expect((new ScanForTrackedChanges)->inspect($odt))->toEqual(Inspection::tracked('changed-region', 'content.xml'));
});

it('takes the host\'s guard from the container: the seam for last-word\'s own signal', function (): void {
    app()->instance(TrackedChangesGuard::class, new class implements TrackedChangesGuard
    {
        public function inspect(string $docx): Inspection
        {
            return Inspection::tracked('ins', 'reported by the reader');
        }
    });
    $model = ScriptedModel::install([tcRetitle(), 'Done.']);

    $result = editDocument(new WorkingCopy(Letterheads::headerLetterheadWithBody(), 'v1'), 'Change the greeting to Mr Example.');

    expect($result->refusal)->toBe(Refusal::TrackedChanges)
        ->and($model->requests())->toBe([]);
});

it('says the refusal to a parent agent, typed', function (): void {
    $store = new InMemoryDocumentStore(tcWithRevision('<w:p><w:ins '.TC_AUTHOR.'><w:r><w:t>added</w:t></w:r></w:ins></w:p>'));
    ScriptedModel::install(['Done.']);

    $answer = docsAgent()->tool(Participant::create(['name' => 'Owner']), $store)->handle('Change the greeting.');

    expect(json_decode((string) $answer, true))->toMatchArray([
        'outcome' => 'failed',
        'message' => DocsResult::TRACKED_CHANGES,
        'refusal' => 'tracked_changes',
    ]);
});

/** Stands in for last-word 0.22's TrackedChangesRefused, which 0.21 does not have. */
final class TcFakeTrackedChangesRefused extends RuntimeException {}

it('scans every word part, footnotes and comments included', function (string $part, string $root): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'tc_');
    file_put_contents($path, Letterheads::headerLetterheadWithBody());
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->addFromString($part, '<?xml version="1.0"?><w:'.$root.' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:del '.TC_AUTHOR.'><w:r><w:delText>x</w:delText></w:r></w:del></w:p></w:'.$root.'>');
    $zip->close();
    $docx = (string) file_get_contents($path);
    unlink($path);

    expect((new ScanForTrackedChanges)->inspect($docx))->toEqual(Inspection::tracked('del', $part));
})->with([
    'footnotes' => ['word/footnotes.xml', 'footnotes'],
    'comments' => ['word/comments.xml', 'comments'],
]);

it('falls back to the scan when the installed last-word has no tracked-changes signal', function (): void {
    $guard = new LastWordTrackedChanges(refused: 'LastWord\Exceptions\NoSuchClassInThisVersion');

    expect($guard->supported())->toBeFalse()
        ->and($guard->inspect(tcWithRevision('<w:p><w:ins '.TC_AUTHOR.'><w:r><w:t>a</w:t></w:r></w:ins></w:p>')))->toEqual(Inspection::tracked('ins', 'word/document.xml'))
        ->and($guard->inspect(Letterheads::headerLetterheadWithBody())->status)->toBe(Inspection::CLEAN);
});

it('uses last-word\'s refuseTrackedChanges when the installed last-word has it', function (): void {
    $asked = 0;
    $guard = new LastWordTrackedChanges(
        read: function (string $docx) use (&$asked): array {
            $asked++;
            throw new TcFakeTrackedChangesRefused('tracked_changes_present');
        },
        refused: TcFakeTrackedChangesRefused::class,
    );

    // A file the scan calls clean: only last-word's refusal can make this tracked.
    expect($guard->supported())->toBeTrue()
        ->and($guard->inspect(Letterheads::headerLetterheadWithBody()))->toEqual(Inspection::tracked('trackedChanges', 'reported by last-word'))
        ->and($asked)->toBe(1);
});

it('counts last-word\'s trackedChanges key as tracked, and lets its clean read through to the scan', function (): void {
    $present = new LastWordTrackedChanges(read: fn (string $docx): array => ['blocks' => [], 'trackedChanges' => ['present' => true, 'insertions' => 1, 'deletions' => 0, 'moves' => 0, 'formatChanges' => 0]], refused: TcFakeTrackedChangesRefused::class);
    $clean = new LastWordTrackedChanges(read: fn (string $docx): array => ['blocks' => []], refused: TcFakeTrackedChangesRefused::class);
    $other = new LastWordTrackedChanges(read: fn (string $docx): never => throw new RuntimeException('archive_limit'), refused: TcFakeTrackedChangesRefused::class);

    expect($present->inspect(Letterheads::headerLetterheadWithBody())->status)->toBe(Inspection::TRACKED)
        ->and($clean->inspect(Letterheads::headerLetterheadWithBody())->status)->toBe(Inspection::CLEAN)
        // last-word's signal only ADDS a refusal: the scan still sees what it does not cover (.odt, say).
        ->and($clean->inspect(tcWithRevision('<w:p><w:ins '.TC_AUTHOR.'/></w:p>'))->status)->toBe(Inspection::TRACKED)
        // Any other reader failure is the scan's to classify.
        ->and($other->inspect(Letterheads::headerLetterheadWithBody())->status)->toBe(Inspection::CLEAN);
});

it('gives the person the same fixed words whichever path found the change', function (): void {
    app()->instance(TrackedChangesGuard::class, new LastWordTrackedChanges(
        read: fn (string $docx): never => throw new TcFakeTrackedChangesRefused('tracked_changes_present'),
        refused: TcFakeTrackedChangesRefused::class,
    ));
    $model = ScriptedModel::install([tcRetitle(), 'Done.']);

    $result = editDocument(new WorkingCopy(Letterheads::headerLetterheadWithBody(), 'v1'), 'Change the greeting to Mr Example.');

    expect($result->refusal)->toBe(Refusal::TrackedChanges)
        ->and($result->message)->toBe(DocsResult::TRACKED_CHANGES)
        ->and($model->requests())->toBe([]);
});

it('binds last-word\'s signal, with the scan, as the default guard', function (): void {
    expect(app(TrackedChangesGuard::class))->toBeInstanceOf(LastWordTrackedChanges::class);
});

it('turns last-word\'s signal on only when the installed last-word has it', function (): void {
    $has = class_exists(LastWordTrackedChanges::REFUSED) && new ReflectionMethod(Agent::class, 'read')->getNumberOfParameters() >= 2;

    // With last-word 0.21 (no TrackedChangesRefused, read() takes no options) this is off and the scan decides.
    expect((new LastWordTrackedChanges)->supported())->toBe($has);
});
