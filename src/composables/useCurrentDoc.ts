import { ref, watch } from 'vue'
import { RouteLocationNormalizedLoaded, Router } from "vue-router"

const currentDoc = ref<string | null>(null)

const getDoc = async (version?: string, page?: string) => {
    const docUrl = `docs/${ version }/${ page }.md`
    currentDoc.value = await fetch(docUrl).then(res => res.text())
}

const watchRouteChanges = async (route: RouteLocationNormalizedLoaded, router: Router) => {
    watch(
        () => route.params.version,
        (newValue) => {
            if (newValue) {
                const { version, page } = route.params
                getDoc(String(version), String(page))
            }
        },
        {
            deep: true
        }
    )

    watch(() => route.name, async (newValue) => {
        if (newValue && !route.params.version) {
            await router.push({ name: 'docs-versioned', params: { version: '1.x', page: 'home' } })
        }
    })
}

export default () => ({
    getDoc,
    currentDoc,
    watchRouteChanges
})
