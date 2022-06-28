<template>
    <div class="d-flex"
         :class="{
            'justify-content-between' : previousLink?.url && nextLink?.url,
            'justify-content-around': !previousLink?.url || !nextLink?.url
         }"
         v-if="Object.keys(currentPageObject || {}).length">
        <a class="btn btn-link text-decoration-none"
           v-if="previousLink?.url"
           :href="`/laravel-a1-pdf-sign/#/docs/${currentDocVersion}/${previousLink.url}`">
            <i class="fa-solid fa-arrow-left-long"></i>
            {{ previousLink.title }}
        </a>
        <a class="btn btn-link text-decoration-none"
           v-if="nextLink?.url"
           :href="`/laravel-a1-pdf-sign/#/docs/${currentDocVersion}/${nextLink.url}`">
            {{ nextLink.title }}
            <i class="fa-solid fa-arrow-right-long"></i>
        </a>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import useCurrentDoc from '@/composables/useCurrentDoc'

const {
    currentPageObject,
    currentDocVersion,
    filterPageObject
} = useCurrentDoc()

const previousLink = computed(() => filterPageObject(currentPageObject?.footerActions?.previousLink))
const nextLink = computed(() => filterPageObject(currentPageObject?.footerActions?.nextLink))
</script>
