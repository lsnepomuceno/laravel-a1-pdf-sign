import { reactive } from 'vue'
import v1x from '@/documentationLinks/v1x'
import v0x from '@/documentationLinks/v0x'

const docs = reactive([
    v1x,
    v0x
])

export default () => ({
    docs
})
