# Changelog

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
