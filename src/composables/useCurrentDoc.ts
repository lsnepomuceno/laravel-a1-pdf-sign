import { reactive, ref, watch } from 'vue'
import { RouteLocationNormalizedLoaded, Router } from "vue-router"
import useDoc from "@/composables/useDoc";

const currentDocMD = ref<string | null>(null)
const currentDocVersion = ref<string | null | undefined>(null)
const currentDocObject = reactive({})
const { docs } = useDoc()

const getDoc = async (version?: string, page?: string) => {
    const docUrl = `docs/${ version }/${ page }.md`
    currentDocVersion.value = version
    filterVersionList(version)
    currentDocMD.value = await fetch(docUrl).then(res => res.text())
}

const changeCurrentVersion = async (version: string, router: Router) => {
    await router.push({ name: 'docs-versioned', params: { version, page: 'home' } })
}

const filterVersionList = (version?: string) => {
    Object.assign(currentDocObject, docs.find(doc => doc.version === version))
}

const generateDocUrl = (sectionUrl: string) => {
    return `/laravel-a1-pdf-sign/#/docs/${ currentDocVersion.value }/${ sectionUrl }`;
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
    currentDocMD,
    currentDocVersion,
    currentDocObject,
    getDoc,
    watchRouteChanges,
    filterVersionList,
    generateDocUrl,
    changeCurrentVersion
})
