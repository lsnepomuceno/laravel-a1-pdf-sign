<template>
    <div class="docs-content">
        <div class="container">
            <Markdown v-if="currentDocMD"
                      :source="currentDocMD"
                      class="docs-article"/>
            <hr/>
        </div>
    </div>
</template>

<script setup>
import Markdown from 'vue3-markdown-it'
import '@/assets/sass/components/mainContent.sass'
import useCurrentDoc from "@/composables/useCurrentDoc";
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()
const { watchRouteChanges, currentDocMD } = useCurrentDoc()

watchRouteChanges(route, router)
</script>
