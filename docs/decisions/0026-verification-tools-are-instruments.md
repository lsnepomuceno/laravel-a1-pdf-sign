# 0026: The verification tools are instruments, and nothing skips

**Status:** implemented.

## Context

This package makes claims a PHP test suite cannot check on its own.

"The signature verifies" is checked by OpenSSL, not by us. "The document is
still structurally sound" is a question about cross-reference tables this
package writes by hand, and the reader most likely to be pointed at the result,
poppler, recovers from a broken one by scanning the file, which hides exactly
the fault worth finding. "The document is still PDF/A" is a verdict only a
conformance validator can give.

So the suite grew instruments: poppler's `pdfsig` and `pdftoppm` from 2.0,
Ghostscript for producing baselines, then veraPDF and qpdf. Each one has earned
its place by finding something the suite passed:

| Instrument | Found |
|---|---|
| `pdfsig` | the archive timestamp overwriting the signature's own `/Contents` placeholder, in a document the whole suite called valid |
| `pdftoppm` | the seal ignoring `SealPlacement::$page`, and later the difference between an opaque and a transparent one |
| veraPDF | the appended revision dropping the trailer `/ID`, and the invisible signature having no appearance dictionary ([0025](0025-what-signing-does-to-pdf-a.md)) |
| qpdf | nothing yet, and it is the cheapest of them |

Two questions came out of that, and they pull in opposite directions.

## Decision

### They are development tooling, and none of them may reach production

A package that shells out to a JVM, or to poppler, to answer a question at
runtime would be a different package. The consuming application would inherit an
installation requirement nobody wrote down, and discover it in production.

**Nothing in `src/` may invoke one.** `tests/Project/ArchTest.php` fails on any literal
naming `verapdf`, `qpdf`, `pdfsig`, `pdftoppm` or `ghostscript` in the package,
tokenised so the comments explaining the rule do not trip it, which the first
version did.

**Nor do they ship.** Everything built for testing is `export-ignore`d.
`tests/Project/DistributionTest.php` asks `git archive` what a release actually
contains, in both directions: nothing built for testing goes out, and the
package still does.

That check found the rule had already drifted. `phpstan.neon`, `pint.json`,
`composer-dependency-analyser.php` and `package-lock.json` were all being
distributed, each added later than the list that was supposed to exclude them.

### They run everywhere, and nothing skips

veraPDF was briefly behind a build argument, installed only by a dedicated
compose service, so the everyday image would not carry a JRE for one group.

**That was the wrong trade.** The group then skipped by default, which means the
conformance claims were unverified on the machine where the work was being done,
and the first CI run of that job could have gone green having validated nothing:
a group that skips itself exits zero.

Both tools are installed in the development image and in CI, and `composer test`
carries `--fail-on-skipped`. Every check has to run somewhere, and a skip is how
one quietly stops.

The skip guards stay in the two files, because a named skip reads better than
`verapdf: not found` for somebody running the suite outside the container. They
cannot hide: `--fail-on-skipped` turns them red.

### Pinned, not latest

veraPDF is pinned to 1.30.2 in the Dockerfile and the workflow. A validator that
changes its verdicts between builds cannot be the thing a gate is measured
against.

qpdf comes from the distribution, and that is already a demonstrated hazard
rather than a theoretical one: qpdf 12 warns about a page with no `/Resources`
where qpdf 11 said nothing, which is why its gate is **comparative**. Signing
must not introduce a complaint the input did not already have, so a fixture's
own faults cannot be mistaken for the signer's.

### Not a REST service

veraPDF publishes `verapdf-rest`, and it was considered. It does not remove the
JVM, it relocates it, and in exchange the gate would depend on a service being
up, on container networking and on a second project to pin. A determinstic
offline check is worth more here than a tidier container, and the whole group
takes eight seconds.

## Consequences

- The development image carries a headless JRE and qpdf. That is the price of
  the checks actually happening, and it is paid once per image build.
- `composer test` fails on a skipped test, package-wide. A test that genuinely
  cannot run has to say so by not existing, or by being in a group that is
  excluded on purpose, like `network`.
- The `network` group stays non-blocking, and that distinction is the point:
  freetsa.org being down is not this package's defect, while veraPDF disagreeing
  is.

## Alternatives rejected

| | Why not |
|---|---|
| `verapdf-rest` as a compose service | Relocates the JVM, adds a network dependency and a second thing to pin |
| Keep veraPDF behind a build argument | The group skips, and the claims go unverified where the work happens |
| Let the PDF/A group skip silently in CI | A job whose whole purpose is that group, going green having run none of it |
| Track veraPDF `latest` | A gate whose verdicts change without anyone changing the code |
| Fail qpdf on any warning | Two fixtures have a fault of their own, and the gate would measure them |
| Let the tools be used from `src/` for convenience | The consuming application inherits an unwritten installation requirement |
