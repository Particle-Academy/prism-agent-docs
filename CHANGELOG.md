# Changelog

## [Unreleased]

### Added

- `DocsAgent`: a Word-document subagent on prism-harness (agent `docs`, mode `edit`) that edits a saved `.docx` or creates one through last-word, with the tools `write_draft`, `report_limit` and `document_schema`.
- `DocumentStore` (saved file and pending draft) and `InMemoryDocumentStore`.
- Picture registration across the whole document, body and every band, so the writer can place each picture by name.
- A self-check of every written file: not empty, placed pictures present with the same bytes, untouched bands kept, and a real change.
- `resources/limits.json`: last-word's documented limits, each citing the last-word text that states it.
- `DocsAgent::tool()`: the agent as one tool for a parent agent.
