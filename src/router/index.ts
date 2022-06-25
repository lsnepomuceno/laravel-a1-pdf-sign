import { createRouter, createWebHashHistory, RouteRecordRaw } from 'vue-router'
import App from '@/App.vue'

const routes: Array<RouteRecordRaw> = [
    {
        path: '/',
        name: 'home',
        component: App
    },
    {
        path: '/docs/:version/:page',
        name: 'docs-versioned',
        component: App
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: {
            template: '<p>Page Not Found</p>'
        }
    }
    // {
    //   path: '/about',
    //   name: 'about',
    //   // route level code-splitting
    //   // this generates a separate chunk (about.[hash].js) for this route
    //   // which is lazy-loaded when the route is visited.
    //   component: () => import(/* webpackChunkName: "about" */ '../views/AboutView.vue')
    // }
]

const router = createRouter({
    history: createWebHashHistory(),
    routes
})

export default router
