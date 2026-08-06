import { reactive } from "vue";
import v2x from "@/documentationLinks/v2x";
import v1x from "@/documentationLinks/v1x";
import v0x from "@/documentationLinks/v0x";
import type { Doc } from "@/interfaces/Doc";

const docs = reactive<Doc[]>([v2x, v1x, v0x]);

export default () => ({
  docs,
});
