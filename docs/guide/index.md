---
layout: home

hero:
  name: Laravel A1 PDF Sign
  text: Digital signatures, wired into Laravel
  tagline: The engine is signet-pdf. This is the half that knows about your container, your config, your disks and your test suite.
  actions:
    - theme: brand
      text: Get started
      link: /guide/getting-started
    - theme: alt
      text: The engine
      link: https://github.com/lsnepomuceno/signet-pdf

features:
  - title: Signs from where your documents already are
    details: A Storage disk, an upload, a path or a stream. The signed document goes back to a disk without touching the local filesystem.
  - title: Fakes with the framework's own tools
    details: A1PdfSign::fake() for signing, Process::fake() for the shell-out, Http::fake() for the timestamp authority, Storage::fake() for the disks.
  - title: Configured once, not at every call site
    details: One config file, env-driven, turned into the engine's own configuration when the container boots. A bad value fails there, naming its key.
---

## What this package is

`lsnepomuceno/laravel-a1-pdf-sign` is a Laravel adapter over
[`lsnepomuceno/signet-pdf`](https://github.com/lsnepomuceno/signet-pdf).

The engine signs PDF files with A1/x509 certificates by appending a revision,
so a second signature never invalidates the first. It builds the CAdES, writes
the Document Security Store, verifies signatures cryptographically, renders the
seal and knows what ICP-Brasil requires. **None of that is in this repository**,
and this documentation does not repeat it: each page that hands off says so and
links straight to the page that answers it
([0039](/decisions/0039-the-core-lives-in-signet-pdf)).

What this package does is everything between that engine and a Laravel
application.
