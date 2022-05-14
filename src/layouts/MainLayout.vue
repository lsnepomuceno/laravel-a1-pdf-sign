<template>
  <div class="md:container md:mx-auto">
    <div class="flex gap-4">
      <SideBar/>

      <MainContent/>
    </div>
  </div>
</template>

<script setup>
import { MainContent, SideBar } from '@/components/'
</script>
