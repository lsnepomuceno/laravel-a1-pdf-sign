import { reactive } from "vue";
import v1x from "@/documentationLinks/v1x";
import v0x from "@/documentationLinks/v0x";
import type { Doc } from "@/interfaces/Doc";

const docs = reactive<Doc[]>([v1x, v0x]);

export default () => ({
  docs,
});
