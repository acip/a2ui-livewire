# Changelog

All notable changes to `acip/a2ui-livewire` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-24

First release: an A2UI **v1.0** renderer for Laravel Livewire.

### Added
- Message processing for `createSurface`, `updateComponents`,
  `updateDataModel`, `deleteSurface`, `callRendererFunction` and
  `agentFunctionResponse`; replies with `action`, `callAgentFunction`,
  `rendererFunctionResponse` and `error`.
- All 18 Basic Catalog components and all 14 Basic Catalog functions, plus
  `@index`.
- A dependency-free default theme (plain Blade views, one stylesheet loaded with
  `@a2uiStyles`, CSS-variable theming, dark mode, bundled Material icons) and an
  optional Flux theme (`A2UI_THEME=flux`).
- Data binding with JSON Pointers and list-template scopes, two-way input
  binding, `@checks` validation and `@accessibility` mapped to ARIA.
- Transports: HTTP (JSON, JSONL, SSE), A2A JSON-RPC with the A2UI extension
  (`message/send` and `message/stream`), in-process callbacks, and server-side
  channels; progressive rendering of streamed replies.
- Custom catalogs, components, renderer functions and agent-side functions.
- Official A2UI conformance suites and all 43 Basic Catalog examples in the
  test suite; demo app with a gallery and an agent playground.
