<template>
    <header class="header fixed-top">
        <div class="branding docs-branding">
            <div class="container-fluid position-relative py-2">
                <div class="docs-logo-wrapper">
                    <button class="docs-sidebar-toggler docs-sidebar-visible me-2 d-xl-none"
                            type="button"
                            @click="toggleDrawer">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                    <div class="site-logo">
                        <a class="navbar-brand">
                            <span class="logo-text">
                                Laravel
                                <span class="text-alt ml-2">
                                    A1 Pdf Sign
                                </span>
                            </span>
                        </a>
                    </div>
                </div>
                <div class="docs-top-utilities d-flex justify-content-end align-items-center">
                    <div class="top-search-box d-none d-lg-flex">
                        <form class="search-form">
                            <input type="text" placeholder="Search the docs..." name="search"
                                   class="form-control search-input">
                            <button type="submit" class="btn search-btn" value="Search"><i class="fas fa-search"></i>
                            </button>
                        </form>
                    </div>

                    <ul class="social-list list-inline mx-md-3 mx-lg-5 mb-0 d-none d-lg-flex">
                        <li class="list-inline-item" v-for="link in links" :key="link.icon">
                            <a :href="link.url" :title="link.title">
                                <i :class="`fab ${link.icon} fa-fw`"></i>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </header>
</template>

<script setup>
import useLayout from "@/composables/useLayout";
import useSocialLinks from "@/composables/useSocialLinks";

const { links } = useSocialLinks()

const {
    toggleDrawer,
    windowResizing
} = useLayout()

windowResizing()
</script>
