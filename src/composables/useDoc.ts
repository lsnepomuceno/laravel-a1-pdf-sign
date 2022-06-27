import { reactive } from 'vue'
import v1x from '@/documentationLinks/v1x'

const docs = reactive([
    v1x
])

export default () => ({
    docs
})
