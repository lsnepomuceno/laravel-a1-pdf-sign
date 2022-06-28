<template>
    <div class="docs-content">
        <div class="container">
            <Markdown v-if="currentDocMD && !fetchErros"
                      :source="currentDocMD"
                      class="docs-article"/>
            <ErrorsAlert v-else-if="fetchErros"/>
            <hr/>
        </div>
    </div>
</template>

<script setup>
import Markdown from 'vue3-markdown-it'
import '@/assets/sass/components/mainContent.sass'
import useCurrentDoc from "@/composables/useCurrentDoc";
import { ErrorsAlert } from '@/components'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()
const { watchRouteChanges, currentDocMD, fetchErros } = useCurrentDoc()

watchRouteChanges(route, router)
</script>
