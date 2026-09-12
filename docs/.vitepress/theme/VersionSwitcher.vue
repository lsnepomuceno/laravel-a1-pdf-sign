<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useData } from 'vitepress'

/**
 * The version control, and the only reason it is a component.
 *
 * A nav item is the same on every page, so a link to an archive could only ever
 * point at the archive's front door: a reader on `/spec/public-api` who wanted
 * the 1.x version of *that page* had to navigate again from the top. This reads
 * the page it is rendered on and links to the same route in the archive when
 * the archive has it, falling back to the archive's home page when it does not
 * (docs/decisions/0112-the-site-documents-one-release-line.md).
 *
 * The routes come from `themeConfig.versions`, which both configurations fill:
 * the current site lists what each tag carries, and an archive lists what the
 * current line carries, so switching keeps the page in either direction.
 *
 * **Every link carries `target="_self"`.** Each line is a separate VitePress
 * application rather than a route of this one, and the client router skips any
 * link with a target. Without it the router intercepts the click, looks for the
 * route inside the build it is already in, and renders its own 404 at an
 * address that is not a 404.
 */

type Line = { label: string; base: string; routes: string[] }
type Versions = { active: string; lines: Line[] }

const { theme, page } = useData()
const props = defineProps<{ screen?: boolean }>()

const versions = computed<Versions | undefined>(
  () => (theme.value as unknown as { versions?: Versions }).versions,
)

/** The page being read, as a route: `spec/public-api.md` becomes `spec/public-api`. */
const route = computed(() => page.value.relativePath.replace(/\.md$/, ''))

const destinations = computed(() =>
  (versions.value?.lines ?? []).map(line => ({
    label: line.label,
    href: line.routes.includes(route.value) ? line.base + route.value : line.base,
    /** Whether the reader lands on the same page or on the front door. */
    keepsPage: line.routes.includes(route.value),
  })),
)

const open = ref(false)

function close(event: Event) {
  if (! (event.target as HTMLElement | null)?.closest?.('.version-switcher')) {
    open.value = false
  }
}

function escape(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    open.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', close)
  document.addEventListener('keydown', escape)
})

onUnmounted(() => {
  document.removeEventListener('click', close)
  document.removeEventListener('keydown', escape)
})
</script>

<template>
  <div v-if="versions" class="version-switcher" :class="{ screen: props.screen }">
    <button
      type="button"
      class="version-switcher-button"
      :aria-expanded="open"
      aria-haspopup="true"
      @click="open = ! open"
    >
      {{ versions.active }}
      <span class="version-switcher-caret" aria-hidden="true">▾</span>
    </button>

    <ul v-show="open" class="version-switcher-menu">
      <li v-for="destination in destinations" :key="destination.href">
        <a :href="destination.href" target="_self">
          {{ destination.label }}
          <span v-if="! destination.keepsPage" class="version-switcher-note">
            home page
          </span>
        </a>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.version-switcher {
  position: relative;
  display: inline-block;
}

/* The navigation bar hides its own menu below this width and offers a screen
   menu instead, where the second instance of this control is rendered. */
@media (max-width: 767px) {
  .version-switcher:not(.screen) {
    display: none;
  }
}

.version-switcher.screen {
  display: block;
  margin-top: 24px;
}

.version-switcher-button {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 0 12px;
  height: var(--vp-nav-height, 55px);
  font-size: 14px;
  font-weight: 500;
  color: var(--vp-c-text-1);
  background: transparent;
  transition: color 0.25s;
}

.version-switcher.screen .version-switcher-button {
  height: auto;
  padding: 8px 0;
  font-size: 16px;
}

.version-switcher-button:hover {
  color: var(--vp-c-brand-1);
}

.version-switcher-caret {
  font-size: 10px;
  opacity: 0.6;
}

.version-switcher-menu {
  position: absolute;
  top: calc(var(--vp-nav-height, 55px) - 8px);
  right: 0;
  z-index: 40;
  min-width: 176px;
  padding: 12px;
  list-style: none;
  border-radius: 12px;
  border: 1px solid var(--vp-c-divider);
  background-color: var(--vp-c-bg-elv);
  box-shadow: var(--vp-shadow-3);
}

.version-switcher.screen .version-switcher-menu {
  position: static;
  padding: 8px 0 0;
  border: 0;
  background: transparent;
  box-shadow: none;
}

.version-switcher-menu a {
  display: block;
  padding: 6px 12px;
  font-size: 14px;
  font-weight: 500;
  white-space: nowrap;
  color: var(--vp-c-text-2);
  transition: color 0.25s;
}

.version-switcher-menu a:hover {
  color: var(--vp-c-brand-1);
}

/* Said rather than discovered on arrival: this line has no such page, so the
   link goes to its front door. */
.version-switcher-note {
  margin-left: 6px;
  font-size: 12px;
  font-weight: 400;
  color: var(--vp-c-text-3);
}
</style>
