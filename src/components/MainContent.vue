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
import { onMounted } from 'vue'
import Markdown from 'vue3-markdown-it'
import '@/assets/sass/components/mainContent.sass'
import useCurrentDoc from "@/composables/useCurrentDoc";

const { getDoc, currentDoc } = useCurrentDoc

onMounted(() => getDoc())
</script>
