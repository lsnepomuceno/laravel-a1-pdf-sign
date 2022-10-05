<template>
  <div class="container loading-skeleton mt-3" v-for="i in 2" :key="i">
    <h1 v-text="'title'" v-for="i in 4" :key="i" />

    <div class="w-100 mt-4 vh-15 blur">div</div>

    <div class="row mt-4">
      <div class="col-5 blur mx-5 mt-2" v-for="i in 6" :key="i">div</div>
    </div>
  </div>
</template>

<script setup>
import "@/assets/sass/components/skeleton.scss";
</script>
