import { h } from 'vue'
import DefaultTheme from 'vitepress/theme'
import type { Theme } from 'vitepress'
import VersionSwitcher from './VersionSwitcher.vue'
import './custom.css'

/**
 * The default theme, restyled through its own custom properties.
 *
 * Nothing is copied out of it and nothing is replaced. A theme that forks the
 * components has to reconcile every VitePress release by hand, which is the
 * cost the monochrome themes on npm carry; overriding tokens survives an
 * upgrade because it never touches what the upgrade changes.
 *
 * There was a release banner here, filled into the `layout-top` slot, and it is
 * gone: the version control in the navigation names the line on every page and
 * carries the link to the archive, so the banner said the same thing twice and
 * took a strip of every screen to do it
 * (docs/decisions/0112-the-site-documents-one-release-line.md).
 *
 * **The one component added, and why it is a component.** A nav item is the
 * same on every page, so it can only ever link to an archive's front door.
 * `VersionSwitcher` reads the page it is rendered on and links to the same
 * route in the other line when that line has it. It is filled into two slots
 * because the default theme has two navigations: the bar, and the screen menu
 * that replaces it below 768px.
 */
export default {
  extends: DefaultTheme,
  Layout: () =>
    h(DefaultTheme.Layout, null, {
      'nav-bar-content-after': () => h(VersionSwitcher),
      'nav-screen-content-after': () => h(VersionSwitcher, { screen: true }),
    }),
} satisfies Theme
