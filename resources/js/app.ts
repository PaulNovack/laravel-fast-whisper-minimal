import { createApp } from 'vue'
import App from './App.vue'
import router from './router'

import './assets/main.css' // if using Tailwind or custom CSS

const app = createApp(App)
app.use(router)
app.mount('#app')
