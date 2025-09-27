import { createRouter, createWebHistory, RouteRecordRaw } from 'vue-router'
import HomePage from '../pages/HomePage.vue'
import AboutPage from '../pages/ListPage.vue'

const routes: Array<RouteRecordRaw> = [
    {
        path: '/',
        name: 'Home',
        component: HomePage,
    },
    {
        path: '/List',
        name: 'List',
        component: AboutPage,
    },
]

const router = createRouter({
    history: createWebHistory(),
    routes,
})

export default router
