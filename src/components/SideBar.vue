<template>
    <div :class="{
            'sidebar-visible' : drawer,
            'sidebar-hidden' : !drawer
        }"
         class="docs-sidebar shadow-none">
        <div class="top-search-box d-lg-none p-3">
            <form class="search-form">
                <input type="text"
                       placeholder="Search the docs..."
                       name="search"
                       class="form-control search-input">
                <button type="submit"
                        class="btn search-btn"
                        value="Search">
                    <i class="fas fa-search"></i>
                </button>
            </form>
        </div>

        <nav id="docs-nav" class="docs-nav navbar">
            <ul class="section-items list-unstyled nav flex-column pb-3">
                <template v-for="(section, key) in version.sections" :key="key">
                    <li class="nav-item section-title">
                        <a class="nav-link scrollto active"
                           :href="generateDocUrl(section)">
                            <span class="theme-icon-holder me-2">
                                <i :class="`fas ${section.icon}`"></i>
                            </span>
                            {{ section.title }}
                        </a>
                    </li>
                    <template v-if="section.subSections?.length">
                        <li class="nav-item"
                            v-for="(subSection, subKey) in section.subSections"
                            :key="subKey">
                            <a class="nav-link scrollto"
                               :href="generateDocUrl(subSection)">
                                {{ subSection.title }}
                            </a>
                        </li>
                    </template>
                </template>
            </ul>
        </nav>
    </div>
</template>

<script setup>
import useLayout from "@/composables/useLayout";
import useDoc from "@/composables/useDoc";
import { computed } from "vue";

const { drawer } = useLayout()
const { docs } = useDoc()
const version = computed(() => docs.find(doc => doc.version === '1.x'))
const generateDocUrl = (sectionOrSubSection) => {
    return `/laravel-a1-pdf-sign/#/docs/${ version.value.version }/${ sectionOrSubSection.url }`;
}

console.log(version.value)

</script>
