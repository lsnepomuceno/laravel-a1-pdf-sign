import { createRouter, createWebHistory } from "vue-router";

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: "/",
      name: "home",
      component: () => import("@/App.vue"),
    },
    {
      path: "/docs/:version/:page",
      name: "docs-versioned",
      component: () => import("@/App.vue"),
    },
    {
      path: "/:pathMatch(.*)*",
      name: "not-found",
      component: {
        template: "<p>Page Not Found</p>",
      },
    },
  ],
});

export default router;
