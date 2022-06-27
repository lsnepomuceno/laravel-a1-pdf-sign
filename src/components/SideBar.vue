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
                <li class="nav-item section-title">
                    <a class="nav-link scrollto active"
                       href="#section-1">
                        <span class="theme-icon-holder me-2">
                            <i class="fas fa-map-signs"></i>
                        </span>
                        Introduction
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link scrollto"
                       href="#item-1-1">
                        Section Item 1.1
                    </a>
                </li>
                <li class="nav-item section-title mt-3">
                    <a class="nav-link scrollto"
                       href="#section-2">
                        <span class="theme-icon-holder me-2">
                            <i class="fas fa-arrow-down"></i>
                        </span>
                        Installation
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link scrollto"
                       href="#item-2-1">
                        Section Item 2.1
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</template>

<script setup>
import useLayout from "@/composables/useLayout";

const { drawer } = useLayout()
</script>
