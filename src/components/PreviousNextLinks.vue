<template>
  <div
    class="d-flex"
    :class="{
      'justify-content-between': previousLink?.url && nextLink?.url,
      'justify-content-around': !previousLink?.url || !nextLink?.url,
    }"
    v-if="Object.keys(currentPageObject || {}).length"
  >
    <router-link
      v-if="previousLink?.url"
      :to="generateDocUrl(previousLink.url)"
    >
      <a class="btn btn-link text-decoration-none">
        <i class="fa-solid fa-arrow-left-long"></i>
        {{ previousLink.title }}
      </a>
    </router-link>

    <router-link v-if="nextLink?.url" :to="generateDocUrl(nextLink.url)">
      <a class="btn btn-link text-decoration-none">
        {{ nextLink.title }}
        <i class="fa-solid fa-arrow-right-long"></i>
      </a>
    </router-link>
  </div>
</template>

<script setup>
import { computed } from "vue";
import useCurrentDoc from "@/composables/useCurrentDoc";

const { currentPageObject, filterPageObject, generateDocUrl } = useCurrentDoc();

const previousLink = computed(() =>
  filterPageObject(currentPageObject?.footerActions?.previousLink)
);
const nextLink = computed(() =>
  filterPageObject(currentPageObject?.footerActions?.nextLink)
);
</script>
