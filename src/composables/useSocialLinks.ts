import { reactive } from 'vue'

export type SocialLinks = {
    url: string
    title: string
    icon: string
    description?: string
}
const links = reactive<SocialLinks[]>([
    {
        url: 'https://github.com/lsnepomuceno/laravel-a1-pdf-sign',
        icon: 'fa-github',
        title: 'GitHub'
    },
    {
        url: 'https://www.linkedin.com/in/lucas-da-silva-nepomuceno',
        icon: 'fa-linkedin',
        title: 'LinkedIn'
    }
])


export default () => ({
    links
})
