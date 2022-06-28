import { reactive } from 'vue'
import v1x from '@/documentationLinks/v1x'
import v0x from '@/documentationLinks/v0x'

export type Doc = {
    version: string
    sections: {
        title: string
        url: string
        icon: string
        subSections?: {
            title: string
            url: string
            footerActions?: {
                previousLink?: string
                nextLink?: string
            }
        }[]
        footerActions?: {
            previousLink?: string
            nextLink?: string
        }
    }[]
}

const docs = reactive<Doc[]>([
    v1x,
    v0x
])

export default () => ({
    docs
})
