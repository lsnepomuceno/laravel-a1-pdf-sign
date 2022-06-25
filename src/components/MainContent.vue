<template>
    <div class="docs-content">
        <div class="container">
            <Markdown v-if="currentDoc"
                      :source="currentDoc"
                      class="docs-article"/>
        </div>
    </div>
</template>

<script setup>
import { watch } from 'vue'
import Markdown from 'vue3-markdown-it'
import '@/assets/sass/components/mainContent.sass'
import useCurrentDoc from "@/composables/useCurrentDoc";
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()
const { getDoc, currentDoc } = useCurrentDoc()

watch(() => route.params.version, (newValue, old) => {
    if (newValue) {
        const { version, page } = route.params
        getDoc(String(version), String(page))
    }
})

watch(() => route.name, (newValue, old) => {
    if (newValue && !route.params.version) {
        router.push({ name: 'docs-versioned', params: { version: '0.x', page: 'home' } })
    }
})
</script>
