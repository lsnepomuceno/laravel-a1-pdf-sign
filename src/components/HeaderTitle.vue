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
                        <li class="list-inline-item"><a href="#"><i class="fab fa-github fa-fw"></i></a></li>
                        <li class="list-inline-item"><a href="#"><i class="fab fa-twitter fa-fw"></i></a></li>
                        <li class="list-inline-item"><a href="#"><i class="fab fa-slack fa-fw"></i></a></li>
                        <li class="list-inline-item"><a href="#"><i class="fab fa-product-hunt fa-fw"></i></a></li>
                    </ul>
                    <a href="https://themes.3rdwavemedia.com/bootstrap-templates/startup/coderdocs-free-bootstrap-5-documentation-template-for-software-projects/"
                       class="btn btn-primary d-none d-lg-flex">Download</a>
                </div>
            </div>
        </div>
    </header>
</template>

<script setup>
import useLayout from "@/composables/useLayout";

const {
    toggleDrawer,
    windowResizing
} = useLayout()

windowResizing()
</script>
