<template>
  <header class="header fixed-top">
    <div class="branding docs-branding">
      <div class="container-fluid position-relative py-2">
        <div class="docs-logo-wrapper">
          <button
            class="docs-sidebar-toggler docs-sidebar-visible me-2 d-xl-none"
            type="button"
            @click="toggleDrawer"
          >
            <span></span>
            <span></span>
            <span></span>
          </button>
          <div class="site-logo">
            <a class="navbar-brand">
              <span class="logo-text">
                Laravel
                <span class="text-alt ml-2"> A1 Pdf Sign </span>
              </span>
            </a>
          </div>
        </div>
        <div
          class="docs-top-utilities d-flex justify-content-end align-items-center"
        >
          <div class="top-search-box d-none">
            <form class="search-form">
              <input
                type="text"
                placeholder="Search the docs..."
                name="search"
                class="form-control search-input"
              />
              <button type="submit" class="btn search-btn" value="Search">
                <i class="fas fa-search"></i>
              </button>
            </form>
          </div>

          <div class="dropdown">
            <a
              class="btn bg-white dropdown-toggle version text-primary py-0"
              href="#"
              role="button"
              id="dropdownMenuLink"
              data-bs-toggle="dropdown"
              aria-expanded="false"
            >
              Version <br />
              {{ currentDocVersion }}
            </a>

            <ul class="dropdown-menu" aria-labelledby="dropdownMenuLink">
              <li v-for="(version, key) in docs" :key="key">
                <a
                  class="dropdown-item cursor-pointer"
                  @click="changeCurrentVersion(version.version, router)"
                >
                  {{ version.version }}
                </a>
              </li>
            </ul>
          </div>

          <ul
            class="social-list list-inline mx-md-3 mx-lg-5 mb-0 d-none d-lg-flex"
          >
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
import useDoc from "@/composables/useDoc";
import useCurrentDoc from "@/composables/useCurrentDoc";
import { useRouter } from "vue-router";

const { links } = useSocialLinks();
const { docs } = useDoc();
const router = useRouter();
const { currentDocVersion, changeCurrentVersion } = useCurrentDoc();

const { toggleDrawer, windowResizing } = useLayout();

windowResizing();
</script>
