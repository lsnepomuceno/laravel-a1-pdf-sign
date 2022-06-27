<template>
    <footer class="footer">
        <div class="container text-center py-5">
            <ul class="social-list list-unstyled pt-4 mb-0">
                <li class="list-inline-item" v-for="link in links" :key="link.icon">
                    <a :href="link.url" :title="link.title">
                        <i :class="`fab ${link.icon} fa-fw`"></i>
                    </a>
                </li>
            </ul>
        </div>
    </footer>
</template>

<script setup>
import useSocialLinks from "@/composables/useSocialLinks";

const { links } = useSocialLinks()
</script>
