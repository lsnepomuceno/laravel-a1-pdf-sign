import { defineConfig } from 'vitepress'
import { release } from './release'
import { index, pages, sections } from './sidebar'

/**
 * The documentation site, built the way signet-pdf's is.
 *
 * `srcDir` is this directory, so the prose stays where the code is and the
 * documents the repository already maintains are published as they are rather
 * than copied. `/docs` is `export-ignore` in `.gitattributes`, so none of this
 * reaches the package a consumer installs.
 *
 * **There is no archive machinery here, and that is deliberate.** signet-pdf
 * builds one archive per superseded line out of its own tags, because every one
 * of those lines was a VitePress site. The lines before 3.0 here were a Vue
 * application on a `docs` branch, which cannot be rebuilt by this config at
 * all. They stay where they are until that deployment is retired, and this
 * site documents the 3.x line only
 * (docs/decisions/0039-the-core-lives-in-signet-pdf.md).
 */
export default defineConfig({
  title: 'Laravel A1 PDF Sign',
  description:
    'Sign PDF files with A1 certificates in Laravel, on top of signet-pdf.',

  // The project page on GitHub Pages is served under the repository name.
  base: '/laravel-a1-pdf-sign/',

  cleanUrls: true,

  // The home page is `guide/index.md`, published at the site root. A page
  // cannot live inside `.vitepress/`: VitePress excludes that directory from
  // routing, so a document put there is simply never published.
  rewrites: { 'guide/index.md': 'index.md' },
  lastUpdated: true,

  // A link that resolves to nothing fails the build, which is the same rule
  // `tests/Project/SpecTest.php` applies to prose, arriving through the other
  // door. The test asks whether a path exists in the repository; this asks
  // whether it exists as a page.
  //
  // The two `releases/` pages climb out of `docs/` deliberately, to name the
  // canonical file GitHub renders. They are the only exception, and they are
  // allowed here rather than by turning the check off.
  ignoreDeadLinks: [/^(\.\/)?(\.\.\/)+(CHANGELOG|UPGRADE)(\.md)?$/],

  markdown: {
    config(md) {
      // The two `releases/` pages include files from the repository root, and
      // those files' links are written from there: `docs/decisions/0039-….md`.
      // Correct where they are authored, and pointing at nothing once the same
      // text is a page under `/releases/`.
      //
      // Rewritten as they render, on those two pages only. This wraps
      // VitePress's own link rule rather than replacing it, and rewrites before
      // calling it, so the dead-link check still sees the rewritten target.
      const included = ['releases/changelog.md', 'releases/upgrade.md']
      const previous = md.renderer.rules.link_open

      md.renderer.rules.link_open = (tokens, index, options, env, self) => {
        if (included.includes(env?.relativePath)) {
          const token = tokens[index]
          const href = token.attrGet('href')

          if (href) {
            token.attrSet(
              'href',
              href
                .replace(/^(\.\/)?CHANGELOG\.md/, '/releases/changelog.md')
                .replace(/^(\.\/)?UPGRADE\.md/, '/releases/upgrade.md')
                .replace(/^(\.\/)?docs\//, '/'),
            )
          }
        }

        return previous
          ? previous(tokens, index, options, env, self)
          : self.renderToken(tokens, index, options)
      }
    },
  },

  head: [['meta', { name: 'theme-color', content: '#ff2d20' }]],

  themeConfig: {
    // `activeMatch` on every entry, because the default is an exact match
    // against `link`: without it "Guide" highlights on the one page it points
    // at and goes dark on every other page of the section.
    nav: [
      { text: 'Guide', link: '/guide/getting-started', activeMatch: '^/guide/' },
      { text: 'Specification', link: '/spec/public-api', activeMatch: '^/spec/' },
      { text: 'Decisions', link: '/decisions/README', activeMatch: '^/decisions/' },
      { text: 'History', link: '/history/decision-log', activeMatch: '^/history/' },
      {
        text: 'Releases',
        activeMatch: '^/releases/',
        items: [
          { text: 'Changelog', link: '/releases/changelog' },
          { text: 'Upgrading', link: '/releases/upgrade' },
          {
            text: 'All releases',
            link: 'https://github.com/lsnepomuceno/laravel-a1-pdf-sign/releases',
          },
        ],
      },
      {
        text: 'The engine',
        link: 'https://github.com/lsnepomuceno/signet-pdf',
      },
      {
        text: 'Packagist',
        link: 'https://packagist.org/packages/lsnepomuceno/laravel-a1-pdf-sign',
      },
    ],

    // Every section's sidebar is answered by the filesystem, so a page that is
    // added and not listed fails the build rather than going unlinked.
    sidebar: {
      '/guide/': sections('guide', [
        { text: 'Getting started', slugs: ['getting-started', 'configuration'] },
        { text: 'Signing', slugs: ['signing', 'disks', 'certificates', 'templates'] },
        { text: 'Reading a document', slugs: ['validation', 'icp-brasil'] },
        { text: 'AI agents', slugs: ['agents', 'mcp', 'agent-signing'] },
        { text: 'Working with it', slugs: ['commands', 'testing', 'upgrading'] },
      ]),
      '/spec/': [{ text: 'Specification', items: pages('spec') }],
      '/decisions/': [{ text: 'Decisions', items: pages('decisions') }],
      '/history/': [{ text: 'History', items: pages('history') }],
      '/releases/': [{ text: 'Releases', items: pages('releases') }],
    },

    outline: { level: [2, 3] },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/lsnepomuceno/laravel-a1-pdf-sign' },
    ],

    editLink: {
      pattern:
        'https://github.com/lsnepomuceno/laravel-a1-pdf-sign/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    search: { provider: 'local' },

    footer: {
      message: `Version ${release().version}. Released under the MIT License.`,
      copyright: 'Copyright © Lucas Nepomuceno',
    },
  },
})

// `index` is exported by sidebar.ts and used above through `sections`; naming
// it here keeps the import honest rather than pruning it into a lie.
void index
