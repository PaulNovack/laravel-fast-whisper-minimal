import { ref, onMounted } from 'vue'

const THEME_KEY = 'theme' // 'light' | 'dark' | 'system'

export function useTheme() {
    const mode = ref<'light' | 'dark' | 'system'>('system')

    const apply = (val: 'light' | 'dark' | 'system') => {
        const root = document.documentElement
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
        const shouldDark = val === 'dark' || (val === 'system' && prefersDark)
        root.classList.toggle('dark', shouldDark)
    }

    const setTheme = (val: 'light' | 'dark' | 'system') => {
        mode.value = val
        localStorage.setItem(THEME_KEY, val)
        apply(val)
    }

    onMounted(() => {
        const saved = (localStorage.getItem(THEME_KEY) as 'light' | 'dark' | 'system') || 'system'
        mode.value = saved
        apply(saved)
    })

    return { mode, setTheme }
}
