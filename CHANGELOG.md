# Changelog

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
