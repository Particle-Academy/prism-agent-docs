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

```php
use Prism\AgentDocs\DocsAgent;
use Prism\AgentDocs\DocsOptions;
use Prism\AgentDocs\DocumentStore;

// Your storage, behind two slots: the file the person saved, and the pending draft.
final class LetterStore implements DocumentStore
{
    public function __construct(private Letter $letter) {}

    public function saved(): ?string { return $this->letter->docx; }        // null: create one
    public function draft(): ?string { return $this->letter->draft_docx; }  // never read by the agent
    public function putDraft(string $bytes): void { $this->letter->update(['draft_docx' => $bytes]); }
}

$result = app(DocsAgent::class)->edit(
    participant: $user,
    store: new LetterStore($letter),
    request: 'Put the divider under the logo and set the header distance to zero.',
    options: new DocsOptions(images: ['new-logo' => $pngBytes]),   // optional extra pictures
    scope: 'letter:'.$letter->id,
);

$result->outcome;   // Outcome::Ready, Limit, Failed or NoChange
$result->message;   // what to show the person, e.g. "The draft is ready."
$result->draft;     // the checked .docx, already written to the store's draft slot
```

Pick the model the usual harness way (`prism-harness.agents.docs.provider` / `.model`, or the
global defaults). The agent runs in its own harness namespace, `docs`, with one mode, `edit`, and
three tools; it adds nothing to your other agents.

### From another agent

`DocsAgent::tool($user, $store)` is a Prism tool, `edit_document`, that a parent agent can be
given. It answers with `{"outcome": "...", "message": "..."}` and never with the document model's
own text, so nothing in a document reaches the parent as instructions.

## What it guarantees

| | |
|---|---|
| **Every picture is placed by name** | Every picture in the saved file, in the body, a table, a footnote and every header and footer, is registered with the writer as `image:picture-N` and listed to the model. A picture is never missing because it sat in the header. |
| **It starts from the saved file** | The model is shown `DocumentStore::saved()`. The draft slot is only ever written, so an empty pending draft is replaced, never revised. |
| **What a draft leaves out is kept** | A draft that omits `page.header` (or the footer, the body, the title, a margin) keeps the saved one. A band is dropped only when the draft lists it in `remove`. |
| **It checks the file it wrote** | The written `.docx` is re-opened with last-word's reader. It must not be empty (unless the host passes `allowEmpty`), every picture placed must be in the file in the same part with the same bytes, every band the draft left out must be the saved band, and it must change something. A draft that fails is returned to the model to fix, and is never saved. |
| **It knows the format's limits** | When a request needs something a `.docx` written by last-word cannot express, the model reports one of the limits below by id. The person is told the limit and the closest alternative, in the catalogue's words. |
| **It does not narrate** | `message` is built from the outcome, not taken from the model: "The draft is ready.", plus a sentence only for something that could not be done. |

No credentials are read, and nothing is fetched: the only network traffic is the model call
the harness makes.

## Limits

From [`resources/limits.json`](resources/limits.json). Each entry quotes the last-word document
that states it, and the test suite fails if that quote is no longer in the installed last-word.

| id | limit | closest alternative |
|---|---|---|
| `side-margins-shared` | A Word page has one pair of side margins, shared by the header, the body and the footer. | Zero side margins for the whole page. |
| `no-bleed-past-margin` | A picture or block always sits inside the page margins. | Zero page margins. |
| `band-no-chart-or-page-break` | A header or footer cannot hold a chart or a page break. | The chart in the body. |
| `band-no-footnote` | A header or footer cannot hold a footnote, nor a footnote another. | The note in the body. |
| `one-page-colour` | One page colour for the whole document. | One colour throughout. |
| `pictures-png-or-jpeg` | Pictures must be PNG or JPEG. | Convert to PNG. |

## Testing

```bash
composer install
vendor/bin/pest                    # no model, no key: every turn is scripted on Prism's fake
php tools/mutation-check.php       # breaks each guarantee above and proves a test fails
```

## License

MIT
