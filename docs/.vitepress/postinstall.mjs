import { existsSync, symlinkSync, lstatSync, unlinkSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Puts `node_modules` where Vite will look for it.
 *
 * The manifest lives in `.vitepress/` so that nothing of the documentation
 * site sits loose in `docs/`, and that is one directory too deep: Vite resolves
 * a bare import from the file importing it, climbing through parent
 * directories, and `.vitepress/` is a sibling of `decisions/` rather than an
 * ancestor. Without this link the build fails on the first markdown file it
 * compiles, with `Rollup failed to resolve import "vue/server-renderer"`.
 *
 * So `docs/node_modules` is a link to `docs/.vitepress/node_modules`. It is
 * gitignored, and it is created here rather than by hand because CI installs
 * from a clean checkout and would otherwise hit exactly the failure above.
 */

const vitepress = dirname(fileURLToPath(import.meta.url))
const target = join(vitepress, 'node_modules')
const link = join(dirname(vitepress), 'node_modules')

if (! existsSync(target)) {
  console.error('postinstall: .vitepress/node_modules is missing, nothing to link')
  process.exit(1)
}

// A stale link, from a checkout where the target was removed, is replaced
// rather than reported: it is the same link either way.
if (lstatSync(link, { throwIfNoEntry: false })?.isSymbolicLink()) {
  unlinkSync(link)
}

if (existsSync(link)) {
  console.error(`postinstall: ${link} exists and is not a link, leaving it alone`)
  process.exit(1)
}

// Relative, so the link keeps working wherever the checkout lives.
symlinkSync(join('.vitepress', 'node_modules'), link, 'junction')

console.log('postinstall: linked docs/node_modules to .vitepress/node_modules')
