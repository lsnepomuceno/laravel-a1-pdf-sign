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
]

const router = createRouter({
    history: createWebHashHistory(),
    routes
})

router.beforeResolve((to) => {
    const regex = 'laravel-a1-pdf-sign'
    if (process.env.NODE_ENV !== 'production' && (new RegExp(regex).test(location.href))) {
        location.href = (`/#${ to.fullPath }`)
    }
})

export default router
