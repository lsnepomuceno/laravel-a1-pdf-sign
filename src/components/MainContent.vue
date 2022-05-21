<template>
  <main class="col">
    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
      <Markdown v-if="currentDoc" :source="currentDoc"/>
    </div>
  </main>
</template>

<script setup>
import { onMounted } from 'vue'
import Markdown from 'vue3-markdown-it'
import '@/assets/sass/components/mainContent.sass'
import useCurrentDoc from "@/composables/useCurrentDoc";

const { getDoc, currentDoc } = useCurrentDoc

onMounted(() => getDoc())
</script>
