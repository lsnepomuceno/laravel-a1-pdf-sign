import { readdirSync, readFileSync } from 'node:fs'
import { basename, join } from 'node:path'

/**
 * The sidebar is answered by the filesystem rather than written by hand.
 *
 * `tests/Project/SpecTest.php` walks `.php`, `.md` and `.yml` and fails on a
 * path that does not resolve, and it does not walk `.ts`. A sidebar listed by
 * hand would therefore be the one place in this repository where a reference
 * can rot with nothing to catch it, and it is also the place where a new page
 * is most easily forgotten. Both are closed here: `pages()` reads the
 * directory, and `sections()` refuses a list that disagrees with it.
 */

const docsRoot = new URL('..', import.meta.url).pathname

type Item = { text: string; link: string }

/** The first `# ` heading, which every document here carries. */
function title(file: string): string {
  const heading = readFileSync(file, 'utf-8')
    .split('\n')
    .find(line => line.startsWith('# '))

  return heading ? heading.slice(2).trim() : basename(file, '.md')
}

/**
 * Every `.md` in `directory`, by filename.
 *
 * `README.md` is excluded, being the section's own index, and `index.md` with
 * it: in `guide/` that file is the home page, published at the site root.
 */
function files(directory: string): string[] {
  return readdirSync(join(docsRoot, directory))
    .filter(name => name.endsWith('.md'))
    .filter(name => name !== 'README.md' && name !== 'index.md')
    .sort()
}

function item(directory: string, name: string): Item {
  return {
    text: title(join(docsRoot, directory, name)),
    link: `/${directory}/${name.replace(/\.md$/, '')}`,
  }
}

/**
 * Every page in a directory, in filename order.
 *
 * Which is the right order for the numbered decision records and for nothing
 * else, so the guide uses `sections()` instead.
 */
export function pages(directory: string): Item[] {
  return files(directory).map(name => item(directory, name))
}

type Group = { text: string; slugs: string[] }

/**
 * A directory split into named groups, in the order a guide is read rather
 * than the order a filesystem lists.
 *
 * The whole definition is checked against the directory at once, in both
 * directions: a slug with no page behind it fails, and a page no group names
 * fails too. The second half is the one that earns this. A page nobody linked
 * is a page nobody reads, and that is the failure a hand-written sidebar
 * produces in silence, which is what `tests/Project/SpecTest.php` would catch
 * if it could see this file.
 */
export function sections(directory: string, groups: Group[]) {
  const present = files(directory).map(name => name.replace(/\.md$/, ''))
  const listed = groups.flatMap(group => group.slugs)

  const missing = listed.filter(slug => ! present.includes(slug))
  const unlisted = present.filter(slug => ! listed.includes(slug))

  if (missing.length > 0) {
    throw new Error(
      `sidebar: ${directory}/ has no page for ${missing.join(', ')}`,
    )
  }

  if (unlisted.length > 0) {
    throw new Error(
      `sidebar: ${unlisted.map(slug => `${directory}/${slug}.md`).join(', ')} is not in the sidebar`,
    )
  }

  return groups.map(group => ({
    text: group.text,
    items: group.slugs.map(slug => item(directory, `${slug}.md`)),
  }))
}

/** Whether a section has an index page to link its own heading to. */
export function index(directory: string): string | undefined {
  return readdirSync(join(docsRoot, directory)).includes('README.md')
    ? `/${directory}/README`
    : undefined
}
