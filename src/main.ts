import { createApp } from "vue";
import { createPinia } from "pinia";
import App from "./App.vue";
import router from "./router";
import "@/assets/sass/global/global.scss";
import "bootstrap/dist/js/bootstrap.bundle.min";

const app = createApp(App);

app.use(createPinia());
app.use(router);

app.mount("#app");
