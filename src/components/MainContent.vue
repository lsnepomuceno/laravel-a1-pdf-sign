<template>
  <div class="docs-content">
    <div class="container">
      <LoadingSkeleton v-if="loadingDoc" />
      <template v-else>
        <h1 v-text="currentPageObject.title" class="mt-3" />
        <hr />
        <Markdown
          v-if="currentDocMD && !fetchErros"
          :source="currentDocMD"
          :html="true"
          :xhtmlOut="true"
          class="docs-article"
        />
        <ErrorsAlert v-else-if="fetchErros" />
        <hr />
        <PreviousNextLinks />
      </template>
    </div>
  </div>
</template>

<script setup>
import Markdown from "vue3-markdown-it";
import "@/assets/sass/components/mainContent.sass";
import useCurrentDoc from "@/composables/useCurrentDoc";
import { ErrorsAlert, LoadingSkeleton, PreviousNextLinks } from "@/components";
import { useRoute, useRouter } from "vue-router";

const route = useRoute();
const router = useRouter();
const {
  watchRouteChanges,
  currentDocMD,
  currentPageObject,
  fetchErros,
  loadingDoc,
} = useCurrentDoc();

watchRouteChanges(route, router);
</script>
