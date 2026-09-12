import { readFileSync } from 'node:fs'

/**
 * Which release the site documents, read rather than written down.
 *
 * The site builds off `main`, so it always describes the next release, and it
 * said so nowhere: a reader who installed `^1` and opened it was reading pages
 * written against `2.x` with nothing on the page to say so (#65).
 *
 * The number comes from the topmost `## [x.y.z]` in the changelog, for the same
 * reason `bin/signet` reads its version from `Composer\InstalledVersions`
 * instead of carrying a literal: a version written in two places is a version
 * that will disagree with itself. The changelog is the file a release edits
 * first, and it is in the repository, which a git tag is not during a shallow
 * CI checkout.
 */
export type Release = {
  /** The full version of the newest section, `2.0.0`. */
  version: string
  /** The line it belongs to, `2.x`, which is what a reader installs. */
  line: string
  /** Whether that section carries a date, which is what a cut release has. */
  released: boolean
}

const CHANGELOG = new URL('../../CHANGELOG.md', import.meta.url)

export function release(): Release {
  const heading = readFileSync(CHANGELOG, 'utf-8')
    .split('\n')
    .find(line => /^## \[\d+\.\d+\.\d+/.test(line))

  if (! heading) {
    throw new Error('release: CHANGELOG.md has no "## [x.y.z]" section to read a version from')
  }

  const version = heading.replace(/^## \[/, '').replace(/].*$/, '')

  return {
    version,
    line: `${version.split('.')[0]}.x`,
    // "## [2.0.0] - 2026-08-13" is cut; "## [2.0.0]" alone is not yet.
    released: / - \d{4}-\d{2}-\d{2}/.test(heading),
  }
}
