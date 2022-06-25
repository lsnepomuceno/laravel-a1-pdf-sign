import { ref } from 'vue'
import { useRouter } from "vue-router"

const currentDoc = ref<string | null>(null)
const router = useRouter()

const getDoc = async (version?: string, page?: string) => {
    const docUrl = `docs/${ version }/${ page }.md`
    currentDoc.value = await fetch(docUrl).then(res => res.text())
}

export default () => ({
    getDoc,
    currentDoc
})
