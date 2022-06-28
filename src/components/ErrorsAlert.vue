<template>
    <div class="callout-block callout-block-warning">
        <div class="content">
            <h4 class="callout-title">
                <span class="callout-icon-holder me-1">
                    <i class="fas fa-bullhorn"></i>
                </span>
                Heads up
            </h4>
            <p>{{ fetchErros }}</p>
        </div>
    </div>
</template>

<script setup>
import useCurrentDoc from "@/composables/useCurrentDoc";

const { fetchErros } = useCurrentDoc()
</script>
