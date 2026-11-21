# prism-agent-docs

A Word-document subagent for [prism-harness](https://github.com/Particle-Academy/prism-harness).
It edits an existing `.docx`, or creates one, through Fancy's
[last-word](https://github.com/Particle-Academy/last-word) writer, and checks the file it
wrote before it hands anything back.

```bash
composer require particle-academy/prism-agent-docs
```

Requires PHP 8.4 or later (last-word's floor), `particle-academy/prism-harness` 0.26.3 or later
and `particle-academy/last-word` 0.21. Run prism-harness's migrations first: the agent is a
named harness agent.

## Usage

The host decides which document is revised. The agent never chooses between the saved file
and a draft: it revises the **working copy** it is given.

### With a `DocumentStore` (the default policy)

```php
use Prism\AgentDocs\DocsAgent;
use Prism\AgentDocs\DocsOptions;
use Prism\AgentDocs\DocumentStore;

// Your storage, behind two slots: the file the person saved, and the pending draft.
final class LetterStore implements DocumentStore
{
    public function __construct(private Letter $letter) {}

    public function saved(): ?string { return $this->letter->docx; }        // null: create one
    public function draft(): ?string { return $this->letter->draft_docx; }
    public function putDraft(string $bytes): void { $this->letter->update(['draft_docx' => $bytes]); }
}

$result = app(DocsAgent::class)->edit(
    participant: $user,
    document: new LetterStore($letter),
    request: 'Now make the logo bigger.',
    options: new DocsOptions(images: ['new-logo' => $pngBytes]),   // optional extra pictures
    scope: 'letter:'.$letter->id,
);

$result->outcome;       // Outcome::Ready, Limit, Failed or NoChange
$result->message;       // what to show the person, e.g. "The draft is ready."
$result->refusal;       // with Failed: Refusal::TrackedChanges or Uncheckable when refused before any model call, else null
$result->limitIds();    // the limits it offers alternatives for; with Ready, the request was partly doable
$result->draft;         // the checked .docx, already written to the store's draft slot
$result->baseVersion;   // the saved version the draft derives from (see below)
```

A store's working copy is **the pending draft when it holds something** (any text or picture,
in the body, a header or a footer), **otherwise the saved file**. So "now make the logo bigger"
revises the draft the person is looking at, and an empty draft is never a starting point. Its
`baseVersion` is always the SAVED file's, `WorkingCopy::versionOf($saved)`: a `sha256:` digest,
or `none` when nothing is saved.

### With a working copy of your own

```php
use Prism\AgentDocs\WorkingCopy;

$result = app(DocsAgent::class)->edit(
    participant: $user,
    document: new WorkingCopy($bytesToRevise, baseVersion: (string) $letter->revision),
    request: 'Retitle it.',
);

$result->draft;         // nothing is written anywhere: keep it yourself
```

`WorkingCopy` takes the bytes to revise (`null` to create a document) and an opaque, non-empty
`baseVersion`: your identifier of the saved version those bytes derive from. The bytes are used
verbatim.

### Saving: the base version is yours to check

The agent never saves. `DocsResult::$baseVersion` is the working copy's, handed back unchanged.
When the person accepts a draft, **check that the saved file is still that version, and refuse
(or re-run the edit) if it has moved on**. Without that check, accepting a draft can silently
overwrite a save made while the edit ran.

Pick the model the usual harness way (`prism-harness.agents.docs.provider` / `.model`, or the
global defaults). The agent runs in its own harness namespace, `docs`, with one mode, `edit`, and
three tools; it adds nothing to your other agents.

### From another agent

`DocsAgent::tool($user, $store)` is a Prism tool, `edit_document`, that a parent agent can be
given. It answers with `{"outcome": "...", "message": "...", "limits": [...], "refusal": null}` and never with the document model's
own text, so nothing in a document reaches the parent as instructions. It takes a store, and
each call resolves the store's working copy afresh, so a second request continues the first one's
draft.

## What it guarantees

| | |
|---|---|
| **Every picture is placed by name** | Every picture in the working copy, in the body, a table, a footnote and every header and footer, is registered with the writer as `image:picture-N` and listed to the model. A picture is never missing because it sat in the header. |
| **The host chooses what is revised** | The model is shown the working copy and nothing else. With a store, that is the pending draft when it holds something, otherwise the saved file, so an empty draft is replaced, never revised. The saved file is never written. |
| **What a draft leaves out is kept** | A draft that omits `page.header` (or the footer, the body, the title, a margin) keeps the working copy's. A band is dropped only when the draft lists it in `remove`. |
| **It checks the file it wrote** | The written `.docx` is re-opened with last-word's reader. It must not be empty (unless the host passes `allowEmpty`), every picture placed must be in the file in the same part with the same bytes, every band the draft left out must be the working copy's band, and it must change something. A draft that fails is returned to the model to fix, and is never saved. |
| **Tracked changes are refused, never read** | last-word 0.21's reader silently ACCEPTS tracked changes: inserted text is kept, deleted text is dropped, and nothing says so. A draft built from that read would throw away every deletion a reviewer proposed. So before the working copy is read, it is checked for revision elements (`w:ins`, `w:del`, `w:moveFrom`/`w:moveTo` and their range marks, `w:rPrChange`, `w:pPrChange`, `w:sectPrChange`, the table, row and cell changes, `w:cellIns`/`w:cellDel`/`w:cellMerge`, ...) in EVERY XML part, the document, headers, footers, footnotes and comments included, by namespace rather than by prefix, and an OpenDocument change region too. One found refuses the edit with no model call: "This document has tracked changes. Accept or reject them first, then ask again." (`Refusal::TrackedChanges`). The check is bounded (entry count, each part's inflated size read with an explicit length, the total, the compression ratio; no DOCTYPE, no entity expansion, no network), and a file it cannot check is refused too: "The document could not be checked for tracked changes, so nothing was changed." (`Refusal::Uncheckable`). A pending draft with tracked changes is never passed over for the saved file. Where the installed last-word has its own signal (0.22.0, `refuseTrackedChanges`), the default guard, `LastWordTrackedChanges`, uses it as well, detected at runtime; bind your own `TrackedChangesGuard` to replace it. |
| **It knows the limits** | When a request needs something a `.docx` written by last-word cannot express, the model reports one of the limits below by id. The person is told the limit and the closest alternative, in the catalogue's words. A limit is a **format** limit only when no `.docx` can hold it; when Word can and last-word does not write it yet, it is a **writer** gap, and the person is told "This writer does not yet ...", never that Word cannot. |
| **An alternative is offered, never applied** | While a limit is reported, every document path its alternatives would change (see the table below) keeps the working copy's value: a draft that changes one is set back before it is written, and the self-check refuses a file that still differs there. A draft written before the limit was reported is written again. So the same request gives the same draft whether or not the model applied the alternative, and the message offers it: "The closest alternative, not applied unless you ask for it: ...". The one exception is a request that asks for exactly that change, below; then the draft makes it and the message says so. |
| **It does the doable part** | A request can be partly doable. "Use no margins for the header" cannot give the header its own side margins, but it can put the header at the top edge of the page: the header DISTANCE, `page.margins.header`, is one value a `.docx` holds. While a limit is reported, each of its doable parts (`expressible` in `limits.json`) that the request asks for, in a document with the band it positions, must be in the draft: a draft without it is refused (`expressible_part_skipped`), and a run that ends without it (a limit reported and nothing drafted, say) is returned to the model once before the result is composed. The outcome is then `Ready` with the limit, and the message says what was done in the catalogue's one sentence, between the two: "The draft is ready. The header now starts at the top edge of the page." plus the offer. Only where it shows: in a document with no band for it, a doable part is set back to the working copy's value like an alternative, so a header distance on a letterhead with no header is never handed over as the change. No doable part is ever one of a limit's alternative paths (the catalogue refuses that), so the side margins stay as they are. |
| **No first-page band nobody asked for** | A draft that adds `page.firstHeader` or `page.firstFooter` when the working copy has none, and the request does not ask for first-page-only, has it written as `page.header` / `page.footer`, which every page shows. An empty one is dropped; one written beside its own `header` / `footer` is refused for the model to fix; the self-check refuses a file that still has one. When the draft does keep a first-page band (the working copy had it, or the request asked), the message adds "The header art appears on the first page only." |
| **It does not narrate** | `message` is built from the outcome, not taken from the model: "The draft is ready.", plus a sentence only for something that could not be done, an alternative it offers, or a first-page-only band. |
| **Bounded by the host** | A request over `maxRequestBytes` (16 KiB by default) and a document with more distinct pictures than `maxImages` (32 by default, last-word's own) are refused before any model call, in fixed words. The picture cap is never raised to fit the file. |
| **Fixed words on failure** | A file that cannot be opened tells the person "The document could not be opened, so nothing was changed." The reader's exception goes to your exception handler through Laravel's `report()`, never into the message. |

No credentials are read, and nothing is fetched: the only network traffic is the model call
the harness makes.

## Limits

From [`resources/limits.json`](resources/limits.json). Each entry quotes the last-word source
that states it, and the test suite fails if that quote is no longer in the installed last-word.
Each has a `kind`, labelled as Fancy's corrected document parity matrix (2026-11-21) does:
`format` when no `.docx` can hold it, `writer` when Word can save it and last-word does not write
it yet. The catalogue refuses a writer gap whose statement does not start "This writer does not
yet" or that says Word, the format, "cannot" or "impossible".

| id | kind | limit | closest alternative | paths kept while reported |
|---|---|---|---|---|
| `side-margins-shared` | writer | This writer does not yet let the header or footer reach past the side margins while the body keeps them (CC-fancy-0055). | Zero side margins for the whole page. | `page.margins.left`, `page.margins.right` |
| `no-bleed-past-margin` | writer | This writer does not yet let a picture or block run past the page margins (CC-fancy-0055). | Zero page margins. | all four `page.margins` |
| `band-no-chart-or-page-break` | format | A header or footer cannot hold a chart or a page break. A chart in one is unverified in Word. | The chart in the body. | none |
| `band-no-footnote` | format | A header or footer cannot hold a footnote, nor a footnote another. Unverified in Word. | The note in the body. | none |
| `one-page-colour` | writer | This writer does not yet give some pages a different background (one page colour per document is the format's; a per-section background is a shape Word can save). | One colour throughout. | none |
| `pictures-png-or-jpeg` | writer | This writer does not yet place pictures other than PNG or JPEG (a hardening choice; a `.docx` carries GIF, EMF, SVG with a PNG fallback). | Convert to PNG. | none |

`side-margins-shared` and `no-bleed-past-margin` also list what such a request can still have:

| doable part | asked by, for example | needs a | value | the person is told |
|---|---|---|---|---|
| `page.margins.header`, the header distance | "no margins for the header", "header margin to zero", "header at the top edge" | header | `0` | "The header now starts at the top edge of the page." |
| `page.margins.footer`, the footer distance | "no margins for the footer", "footer margin to zero", "footer at the bottom edge" | footer | `0` | "The footer now ends at the bottom edge of the page." |

The phrases are read the same narrow way as `askedBy` below. A header distance in a document with
no header changes nothing anyone can see, so it is neither asked of the draft there nor kept: it is
set back, and a draft left with no other change is refused as a no-op. The sentence is said only
when the draft makes the change; a working copy that already had it hears nothing of it.

A limit with no paths has alternatives that move content (a chart into the body, a note into the
text) rather than set one value, so there is no single path to keep; for those the rule rests
on the prompt and on the no-change check.

### When a request asks for the change itself

The test is deliberately narrow, and every doubt counts as "not asked":

- The request must contain one of the limit's `askedBy` phrases from `limits.json`, whole, on
  word boundaries, ignoring case and spacing. For `side-margins-shared` these all name the
  whole page or document: "set the side margins to zero for the whole page" asks; "no margins
  for the header" and "side margins to zero for the header" do not.
- The digit `0` reads as "zero", also at the end of a sentence, so "side margins to 0 for the
  whole page" asks too; "0.5" is not zero.
- A phrase with a negation in the four words before it does not count: "don't set the side
  margins to zero for the whole page".
- A path asked for by any limit's phrases may change even while another reported limit claims
  it; the request named it.

First-page-only is read the same way, from a fixed list in `Support\Asked::FIRST_PAGE_ONLY`
("first page only", "only on the first page", "different first page", "first-page header", ...).

## Testing

```bash
composer install
vendor/bin/pest                    # no model, no key: every turn is scripted on Prism's fake
php tools/mutation-check.php       # breaks each guarantee above and proves a test fails
```

## License

MIT
