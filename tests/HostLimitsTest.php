<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use Prism\AgentDocs\DocsOptions;
use Prism\AgentDocs\DocsResult;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\Outcome;
use Prism\AgentDocs\Source;
use Prism\AgentDocs\Tools\DocsTools;
use Prism\AgentDocs\WorkingCopy;
use Prism\AgentDocs\Workspace;
use Tests\Support\Letterheads;
use Tests\Support\ScriptedModel;

/*
|--------------------------------------------------------------------------
| What the host bounds, and what the person is told when a bound is hit
|--------------------------------------------------------------------------
|
| An unreadable file, too many pictures, a request too long: each is refused
| before any model call, in fixed words. The reader's exception text is the
| host's to see, through its reporter, and never the person's.
|
*/

const NOT_A_DOCX = "PK\x03\x04 not really a docx <script>";

it('tells the person fixed words when the saved file is unreadable, and reports the detail to the host', function (): void {
    Exceptions::fake();
    $model = ScriptedModel::install(['Done.']);

    $result = editDocument(new InMemoryDocumentStore(NOT_A_DOCX), 'Anything.');

    expect($result->outcome)->toBe(Outcome::Failed)
        ->and($result->message)->toBe(DocsResult::UNREADABLE)
        ->and($model->requests())->toBe([]);
    // The detail went to the host's reporter, once, and not to the person.
    Exceptions::assertReportedCount(1);
});

it('tells the person fixed words when a pending draft is unreadable, rather than passing over it', function (): void {
    Exceptions::fake();
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead(), NOT_A_DOCX);
    $model = ScriptedModel::install(['Done.']);

    $result = editDocument($store, 'Anything.');

    expect($result->message)->toBe(DocsResult::UNREADABLE)
        ->and($model->requests())->toBe([])
        ->and($store->draft())->toBe(NOT_A_DOCX);
    Exceptions::assertReportedCount(1);
});

it('tells the person fixed words when a host working copy is unreadable', function (): void {
    Exceptions::fake();

    $result = editDocument(new WorkingCopy(NOT_A_DOCX, 'v1'), 'Anything.');

    expect($result->message)->toBe(DocsResult::UNREADABLE);
    Exceptions::assertReportedCount(1);
});

it('refuses a file with more pictures than the configured maximum, in fixed words', function (): void {
    $model = ScriptedModel::install(['Done.']);

    $result = editDocument(new InMemoryDocumentStore(Letterheads::withPictures(3)), 'Anything.', new DocsOptions(maxImages: 2));

    expect($result->outcome)->toBe(Outcome::Failed)
        ->and($result->message)->toBe(DocsResult::TOO_MANY_PICTURES)
        ->and($model->requests())->toBe([]);
});

it('counts the host\'s offered pictures towards the maximum', function (): void {
    ScriptedModel::install(['Done.']);

    $result = editDocument(new InMemoryDocumentStore(Letterheads::withPictures(2)), 'Anything.', new DocsOptions(images: ['logo' => Letterheads::logo()], maxImages: 2));

    expect($result->message)->toBe(DocsResult::TOO_MANY_PICTURES);
});

it('accepts a file at exactly the maximum', function (): void {
    ScriptedModel::install(['Done.']);

    $result = editDocument(new InMemoryDocumentStore(Letterheads::withPictures(2)), 'Anything.', new DocsOptions(maxImages: 2));

    expect($result->outcome)->toBe(Outcome::NoChange);
});

it('refuses past last-word\'s own 32 by default, rather than raising the limit to fit the file', function (): void {
    $model = ScriptedModel::install(['Done.']);

    $result = editDocument(new InMemoryDocumentStore(Letterheads::withPictures(DocsOptions::MAX_IMAGES + 1)), 'Anything.');

    expect($result->message)->toBe(DocsResult::TOO_MANY_PICTURES)
        ->and($model->requests())->toBe([]);
});

it('hands the writer the configured maximum, never the file\'s picture count', function (): void {
    $workspace = new Workspace(Source::fromWorkingCopy(new WorkingCopy(Letterheads::withPictures(3), 'v1')), docsAgent()->catalog(), new DocsOptions(maxImages: 5, imageLimits: ['maxBytes' => 1000]));

    expect($workspace->writerOptions()['imageLimits'])->toBe(['maxBytes' => 1000, 'maxImages' => 5])
        ->and($workspace->pictureCount())->toBe(3);
});

it('takes the picture count from one knob only', function (): void {
    new DocsOptions(imageLimits: ['maxImages' => 1000]);
})->throws(InvalidArgumentException::class);

it('refuses a request longer than the configured cap, in fixed words, before the model', function (): void {
    $model = ScriptedModel::install(['Done.']);
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());

    $result = editDocument($store, str_repeat('x', 101), new DocsOptions(maxRequestBytes: 100));

    expect($result->outcome)->toBe(Outcome::Failed)
        ->and($result->message)->toBe(DocsResult::REQUEST_TOO_LONG)
        ->and($model->requests())->toBe([])
        ->and($store->draft())->toBeNull();
});

it('accepts a request at exactly the cap', function (): void {
    ScriptedModel::install([[ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['title' => 'New']])], 'Done.']);

    $result = editDocument(new InMemoryDocumentStore(Letterheads::bodyLetterhead()), str_repeat('x', 100), new DocsOptions(maxRequestBytes: 100));

    expect($result->outcome)->toBe(Outcome::Ready);
});

it('caps the request at 16 KiB by default, measured in bytes', function (): void {
    $model = ScriptedModel::install(['Done.']);

    // 5462 three-byte characters: under 16384 characters, over 16384 bytes.
    $result = editDocument(new InMemoryDocumentStore(Letterheads::bodyLetterhead()), str_repeat('€', 5462), new DocsOptions);

    expect(DocsOptions::MAX_REQUEST_BYTES)->toBe(16384)
        ->and($result->message)->toBe(DocsResult::REQUEST_TOO_LONG)
        ->and($model->requests())->toBe([]);
});
