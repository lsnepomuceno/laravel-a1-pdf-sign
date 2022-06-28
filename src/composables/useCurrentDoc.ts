import { reactive, ref, watch } from 'vue'
import { RouteLocationNormalizedLoaded, Router } from "vue-router"
import useDoc, { Doc } from "@/composables/useDoc";

const currentDocMD = ref<string | null>(null)
const currentDocVersion = ref<string | null | undefined>(null)
const currentDocObject = reactive(<Doc>{})
const currentPageObject = reactive({})
const fetchErros = ref<string | null | undefined>(null)
const { docs } = useDoc()

const filterVersionList = (version?: string) => {
    Object.assign(currentDocObject, docs.find(doc => doc.version === version))
}

const filterPageObject = (page?: string) => {
    if (Object.keys(currentDocObject)) {
        return currentDocObject
            .sections
            .find(
                section => section.url === page
                    || section.subSections?.find(sub => sub.url === page)
            )
    }

    return {}
}

const getDoc = (version?: string, page?: string) => {
    const markdownUrl = `/docs/${ version }/${ page }.md`
    currentDocVersion.value = version
    filterVersionList(version)
    Object.assign(currentPageObject, filterPageObject(page))
    fetchErros.value = null
    currentDocMD.value = null
    fetch(markdownUrl)
        .then(async res => {
            currentDocMD.value = await res.text()
            if (res.status >= 400) {
                fetchErros.value = 'The page you requested was not found.'
            }
        })
        .catch(() => {
            fetchErros.value = 'An error occurred during the process.'
        })
}

const changeCurrentVersion = async (version: string, router: Router) => {
    await router.push({ name: 'docs-versioned', params: { version, page: 'home' } })
}

const generateDocUrl = (sectionUrl: string) => {
    return `/docs/${ currentDocVersion.value }/${ sectionUrl }`;
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
    fetchErros,
    currentPageObject,
    filterPageObject,
    getDoc,
    watchRouteChanges,
    filterVersionList,
    generateDocUrl,
    changeCurrentVersion
})
