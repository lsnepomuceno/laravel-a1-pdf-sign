<template>
    <div :class="{
            'sidebar-visible' : drawer,
            'sidebar-hidden' : !drawer
        }"
         class="docs-sidebar shadow-none">
        <div class="top-search-box d-lg-none p-3 d-none">
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
                <template v-for="(section, key) in currentDocObject.sections" :key="key">
                    <li class="nav-item section-title">
                        <router-link :to="generateDocUrl(section.url)">
                            <a class="nav-link scrollto active">
                                <span class="theme-icon-holder me-2">
                                    <i :class="`fas ${section.icon}`"></i>
                                </span>
                                {{ section.title }}
                            </a>
                        </router-link>
                    </li>
                    <template v-if="section.subSections?.length">
                        <li class="nav-item"
                            v-for="(subSection, subKey) in section.subSections"
                            :key="subKey">
                            <router-link :to="generateDocUrl(subSection.url)">
                                <a class="nav-link scrollto">
                                    {{ subSection.title }}
                                </a>
                            </router-link>
                        </li>
                    </template>
                </template>
            </ul>
        </nav>
    </div>
</template>

<script setup>
import useLayout from '@/composables/useLayout'
import useCurrentDoc from '@/composables/useCurrentDoc'

const { currentDocObject, generateDocUrl } = useCurrentDoc()
const { drawer } = useLayout()

</script>
