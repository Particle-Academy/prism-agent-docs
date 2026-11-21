# Changelog

## [0.1.6]

A fix from the G-316 live lab run 01a2007d (scenarios S4 and R2, which passed every structural check and failed the judge).

No breaking changes.

Nothing in the public API is removed or renamed. `Workspace::limits()` and `DocsResult::$limits` may now hold `null` for `alternative`, when no alternative is left to offer.

### Fixed

- **Never offer what the document already is.** The model chose "keep the header art inside the page margins, as it is now" on a letterhead whose art already was, and the person was offered their own layout. The offered alternative is now decided in code: the model's choice unless the working copy already holds every value it would set, else the closest alternative that would change something, else none, and then the message is the limit alone. On S4 and R2 the offer is now "set the side margins to zero for the whole page".

### Added

- `alternativeChanges` in `resources/limits.json`: per alternative, the values it would set, `{}` for one that changes nothing, or `null` for one that moves content. The catalogue refuses one that does not line up with the alternatives or names a path outside the limit's `alternativePaths`. `side-margins-shared` and `no-bleed-past-margin` declare theirs.
- `Limit::$alternativeChanges`, `Limit::isCurrentState()` and `Limit::offer()`.
- Mutants M54 to M56 in `tools/mutation-check.php`; M20 re-pointed.

## [0.1.5]

A data-loss guard, and the limits catalogue re-labelled to Fancy's corrected document parity matrix (G-316).

No breaking changes.

Nothing in the public API is removed or renamed, and no limit id changes. A document with tracked changes, which 0.1.4 edited, is now refused; four limits are told in new words; the `edit_document` answer gains a `refusal` key.

### Fixed

- **Tracked changes are refused, never read.** last-word 0.21's reader silently accepts tracked changes: inserted text is kept, deleted text is dropped, and nothing says so, so a draft built from that read threw away every deletion a reviewer proposed. Before the working copy is read it is now checked for WordprocessingML revision elements (`w:ins`, `w:del`, `w:moveFrom`, `w:moveTo` and the move range marks, `w:rPrChange`, `w:pPrChange`, `w:sectPrChange`, `w:numberingChange`, the table, row and cell changes, `w:cellIns`, `w:cellDel`, `w:cellMerge`, the custom-XML range marks), in every XML part (document, headers, footers, footnotes, comments, styles), matched by namespace rather than prefix; and for an OpenDocument change region. One found refuses the edit in fixed words, with no model call: "This document has tracked changes. Accept or reject them first, then ask again." The check is bounded (archive size, entry count, each part's inflated size read with an explicit length, the total, the compression ratio), never expands an entity, refuses a part with a DOCTYPE, and refuses a file it cannot check: "The document could not be checked for tracked changes, so nothing was changed."
- **A pending draft with tracked changes is never passed over.** A draft of tracked deletions only reads as empty, so the store default would have revised the saved file instead. It is now the working copy, and is refused.
- **A writer gap is no longer told as impossible.** `side-margins-shared`, `no-bleed-past-margin`, `one-page-colour` and `pictures-png-or-jpeg` said Word or the format could not do something Word can save and last-word does not write yet. Each now says "This writer does not yet ...", and the message still offers the closest option.

### Added

- `Refusal` (`tracked_changes`, `uncheckable`), `DocsResult::$refusal`, `DocsResult::refused()`, `DocsResult::TRACKED_CHANGES` and `DocsResult::UNCHECKABLE`; `edit_document` answers with `refusal` too.
- `Revisions\TrackedChangesGuard`, bound in the container, with `Revisions\ScanForTrackedChanges` (the bounded archive scan) and `Revisions\LastWordTrackedChanges`, the default: last-word 0.22.0's own signal (`Agent::read($bytes, ['refuseTrackedChanges' => true])` and its `trackedChanges` key), detected at runtime with no constraint bump, and the scan always, since last-word's signal covers .docx and .dotx only. `DocsAgent` takes a guard as its last constructor argument; `WorkingCopy::fromStore()` takes one as its second.
- `kind` on every limit in `resources/limits.json`, required: `format` (no .docx can hold it) or `writer` (Word can; last-word does not write it yet), with `Limit::FORMAT`, `Limit::WRITER`, `Limit::$kind` and `Limit::isWriterGap()`. The catalogue refuses a writer gap whose statement does not start "This writer does not yet", or names Word or the format, or says "cannot" or "impossible". The model is told each limit's kind.
- Citations from last-word's source for each re-labelled limit, and a `council` note naming Fancy's gap item (CC-fancy-0055, items 11 and 17) or, for the two format limits, what is still unverified in Word.
- `ext-zip` and `ext-xmlreader` in `require` (last-word already needs `ext-zip`).
- Mutants M43 to M53 in `tools/mutation-check.php`.

## [0.1.4]

A fix from the G-316 live lab run 01a20049 (scenario S3), which found a regression in 0.1.3.

No breaking changes. Nothing in the public API is removed or renamed. A message for a partly doable request now carries one more sentence, and every catalogue `expressible` part needs a `done` sentence.

### Fixed

- **A doable part only where it shows.** On a body-designed letterhead with no page header, "Use no margins for the header" set `page.margins.header` to 0 and was handed over as "The draft is ready." plus the offer: a header distance with no header changes nothing anyone sees, so that was a no-op draft presented as the change. While a limit is reported, each of its doable parts is now set back to the working copy's value, like an alternative's paths, when the draft has no band it positions, and a draft written before the limit was reported is written again. With nothing else changed, the existing no-change rule refuses the draft, and the outcome is `limit` again.
- **The message says what the draft did.** When a reported limit's doable part is in the draft, the message says so in one plain sentence from the catalogue, between "The draft is ready." and the offer: "The draft is ready. The header now starts at the top edge of the page. A Word page has one pair of side margins, ...". Only for a change the draft made; a working copy that already had it hears nothing of it.

### Added

- `done` on every `expressible` part in `resources/limits.json` (required: one plain sentence ending with a full stop), and `Expressible::$done`.
- `Workspace::unshownParts()` and `Workspace::appliedParts()`.
- The prompt says a doable part applies only when the document has the band.
- Owner-rule test: a partly doable message is "The draft is ready." plus only catalogue sentences.
- Mutants M38 to M42 in `tools/mutation-check.php`.

## [0.1.3]

A fix from the G-316 live lab run 01a2002d (scenario S4, claude-sonnet-5-5).

No breaking changes. Nothing in the public API is removed or renamed. The `edit_document` tool's answer gains a `limits` key, and a request that is partly doable now gets a draft where it used to get only the limit.

### Fixed

- **Do what can be done, offer the rest.** "Use no margins for the header", on a letterhead with a header, reported `side-margins-shared` and wrote no draft, so the person heard only about the side margins. The header DISTANCE, `page.margins.header`, is something a `.docx` holds. A request can be partly doable, and reporting a limit no longer ends the edit: while a limit is reported, each of its doable parts that the request asks for must be in the draft. A draft without one is refused for the model to fix, and a run that ends without one is returned to the model once. The result is `Ready` with the limit, and the message is "The draft is ready." plus the offer. The side margins are never changed: a doable part can never be an alternative path.
- **"Set it to 0." reads as zero.** The narrow request matcher read the digit 0 as "zero" only when no full stop followed it, so a sentence ending in "0." did not count. A 0 before a digit ("0.5") still does not.

### Added

- `expressible` in `resources/limits.json`: the parts of a limited request a `.docx` can do, each with its path, value, an instruction for the model, the phrases that ask for it, the bands it needs and its last-word sources. `side-margins-shared` and `no-bleed-past-margin` list the header and footer distances. The catalogue refuses one that is an alternative path.
- `Expressible` (in `Limits`), `Limit::$expressible`, `Workspace::requiredParts()` and `Workspace::skippedParts()`.
- A self-check rule and problem code, `expressible_part_skipped`.
- `DocsAgent::PUSHBACKS` (1): how many times a run that skipped a doable part is returned to the model.
- `DocsResult::limitIds()`; `edit_document` answers with `limits` too.
- The system prompt says a request can be partly doable and lists each limit's doable parts. The code above is what enforces it.
- Mutants M27 to M37 in `tools/mutation-check.php`.

### Tests

- `tests/Support/ScriptedModel.php` now answers with the whole conversation, the request's messages first, as Prism's provider handlers do. It used to return only the new turns, so a second send on a thread reached the model without the person's first message.

## [0.1.2]

Two fixes from a live test of 0.1.0 against a real letterhead.

No breaking changes. Nothing in the public API is removed or renamed; the one visible difference is the wording of an offered alternative in `message`.

### Fixed

- **An alternative is offered, never applied.** The same request ("use this file as a letterhead template with no margins for the header") reported `side-margins-shared` every time, but some drafts already had zero side margins while the message offered them. Now, while a limit is reported, every path its alternatives would change (`alternativePaths` in `limits.json`) is set back to the working copy's value before the draft is written, and a draft that passed before the limit was reported is written again. The draft is the same whether or not the model applied the alternative. The exception is a request that asks for exactly that change, by one of the limit's `askedBy` phrases; then the draft makes it and the message says it was done.
- **The message matches the draft.** An offered alternative now reads "The closest alternative, not applied unless you ask for it: ..." instead of "The closest alternative: ...". An applied one reads as done, in the catalogue's `applied` words.
- **No first-page header nobody asked for.** A draft that added `page.firstHeader` / `page.firstFooter` when the working copy had none put the letterhead art on page one only. Unless the request asks for first-page-only, such a band is now written as `page.header` / `page.footer`; an empty one is dropped, and one written beside its own `header` / `footer` is refused for the model to fix. When the draft keeps a first-page band, the message adds one line saying so.

### Added

- Two self-check rules: the file holds the working copy's value at every reported limit's alternative paths, and it has no first-page band the working copy lacked unless asked. Problem codes `alternative_applied` and `first_page_band`.
- `Limit::$alternativePaths`, `Limit::$askedBy` and `Limit::$applied`, read from `limits.json`; `side-margins-shared` and `no-bleed-past-margin` carry them.
- `write_draft` results carry `changed`, naming what was set back or moved, so the model is told.
- `DocsResult::OFFER` and `DocsResult::FIRST_PAGE_ONLY`, the wording above.
- Mutants M14 to M26 in `tools/mutation-check.php`, one per new rule.
- The system prompt says an alternative is offered, not applied, and that `header` / `footer` are for what every page shows. The code above is what enforces both.

## [0.1.0]

The first release. Nothing to break: no earlier version exists.

### Added

- `DocsAgent`: a Word-document subagent on prism-harness (agent `docs`, mode `edit`) that edits a `.docx` or creates one through last-word, with the tools `write_draft`, `report_limit` and `document_schema`.
- `WorkingCopy`: the document an edit revises, chosen by the HOST, with an opaque `baseVersion` naming the saved version it derives from. `DocsAgent::edit()` takes a `WorkingCopy` (used verbatim, nothing written) or a `DocumentStore`.
- `DocumentStore` (saved file and pending draft) and `InMemoryDocumentStore`. A store's working copy is the pending draft when it holds any text or picture, otherwise the saved file; an empty draft is never a starting point. Its base version is the saved file's, `WorkingCopy::versionOf()`.
- `DocsResult::$baseVersion`: the working copy's base version, handed back so the host can refuse to save a draft once the saved file has moved past it. The agent never saves.
- Picture registration across the whole document, body and every band, so the writer can place each picture by name.
- A self-check of every written file: not empty, placed pictures present with the same bytes, untouched bands equal to the working copy's, and a real change.
- `resources/limits.json`: last-word's documented limits, each citing the last-word text that states it.
- `DocsAgent::tool()`: the agent as one tool for a parent agent; each call continues the store's working copy.
- `DocsOptions::$maxImages` (default 32) and `DocsOptions::$maxRequestBytes` (default 16 KiB): a document with more pictures, or a longer request, is refused before any model call, in fixed words.

### Security

- A file that cannot be opened gives the person fixed wording; the reader's exception is passed to the host's exception handler through `report()` and never shown.
- The picture cap is the host's configured maximum and is never raised to fit the file.
