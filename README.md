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
given. It answers with `{"outcome": "...", "message": "..."}` and never with the document model's
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
| **It knows the format's limits** | When a request needs something a `.docx` written by last-word cannot express, the model reports one of the limits below by id. The person is told the limit and the closest alternative, in the catalogue's words. |
| **An alternative is offered, never applied** | While a limit is reported, every document path its alternatives would change (see the table below) keeps the working copy's value: a draft that changes one is set back before it is written, and the self-check refuses a file that still differs there. A draft written before the limit was reported is written again. So the same request gives the same draft whether or not the model applied the alternative, and the message offers it: "The closest alternative, not applied unless you ask for it: ...". The one exception is a request that asks for exactly that change, below; then the draft makes it and the message says so. |
| **No first-page band nobody asked for** | A draft that adds `page.firstHeader` or `page.firstFooter` when the working copy has none, and the request does not ask for first-page-only, has it written as `page.header` / `page.footer`, which every page shows. An empty one is dropped; one written beside its own `header` / `footer` is refused for the model to fix; the self-check refuses a file that still has one. When the draft does keep a first-page band (the working copy had it, or the request asked), the message adds "The header art appears on the first page only." |
| **It does not narrate** | `message` is built from the outcome, not taken from the model: "The draft is ready.", plus a sentence only for something that could not be done, an alternative it offers, or a first-page-only band. |
| **Bounded by the host** | A request over `maxRequestBytes` (16 KiB by default) and a document with more distinct pictures than `maxImages` (32 by default, last-word's own) are refused before any model call, in fixed words. The picture cap is never raised to fit the file. |
| **Fixed words on failure** | A file that cannot be opened tells the person "The document could not be opened, so nothing was changed." The reader's exception goes to your exception handler through Laravel's `report()`, never into the message. |

No credentials are read, and nothing is fetched: the only network traffic is the model call
the harness makes.

## Limits

From [`resources/limits.json`](resources/limits.json). Each entry quotes the last-word document
that states it, and the test suite fails if that quote is no longer in the installed last-word.

| id | limit | closest alternative | paths kept while reported |
|---|---|---|---|
| `side-margins-shared` | A Word page has one pair of side margins, shared by the header, the body and the footer. | Zero side margins for the whole page. | `page.margins.left`, `page.margins.right` |
| `no-bleed-past-margin` | A picture or block always sits inside the page margins. | Zero page margins. | all four `page.margins` |
| `band-no-chart-or-page-break` | A header or footer cannot hold a chart or a page break. | The chart in the body. | none |
| `band-no-footnote` | A header or footer cannot hold a footnote, nor a footnote another. | The note in the body. | none |
| `one-page-colour` | One page colour for the whole document. | One colour throughout. | none |
| `pictures-png-or-jpeg` | Pictures must be PNG or JPEG. | Convert to PNG. | none |

A limit with no paths has alternatives that move content (a chart into the body, a note into the
text) rather than set one value, so there is no single path to keep; for those the rule rests
on the prompt and on the no-change check.

### When a request asks for the change itself

The test is deliberately narrow, and every doubt counts as "not asked":

- The request must contain one of the limit's `askedBy` phrases from `limits.json`, whole, on
  word boundaries, ignoring case and spacing. For `side-margins-shared` these all name the
  whole page or document: "set the side margins to zero for the whole page" asks; "no margins
  for the header" and "side margins to zero for the header" do not.
- The digit `0` reads as "zero", so "side margins to 0 for the whole page" asks too.
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
