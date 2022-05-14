import { ref } from 'vue'

const currentDoc = ref<string | null>(null)

const getDoc = async (docUrl?: string) => {
    docUrl = docUrl || 'docs/0.x/installation.md'
    currentDoc.value = await fetch(docUrl).then(res => res.text())
}

export default {
    getDoc,
    currentDoc
}
