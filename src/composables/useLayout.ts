import { ref } from 'vue'

const drawer = ref<boolean>(false)

const toggleDrawer = () => {
    drawer.value = !drawer.value
}

const responsiveSidebar = () => {
    const windowWidth = window.innerWidth
    drawer.value = windowWidth >= 1200
}

const windowResizing = () => {
    window.onload = () => responsiveSidebar();
    window.onresize = () => responsiveSidebar();
}

export default () => ({
    drawer,
    toggleDrawer,
    windowResizing
})
