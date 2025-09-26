<template>
    <header class="sticky top-0 z-50 border-b border-gray-200 bg-white/90 backdrop-blur shadow-sm dark:border-gray-800 dark:bg-gray-900/90">
        <div class="mx-auto max-w-screen-xl px-4">
            <!-- Taller header on mobile for bigger touch area -->
            <nav class="flex h-16 items-center justify-between sm:h-16 md:h-16" aria-label="Main">
                <!-- Brand -->
                <RouterLink to="/" class="flex items-center gap-2">
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-indigo-600 text-white text-base font-bold shadow-sm sm:h-9 sm:w-9">
            V
          </span>
                    <span class="text-[18px] font-semibold tracking-tight sm:text-[16px]">Vue Demo</span>
                </RouterLink>

                <!-- Desktop Nav -->
                <div class="hidden items-center gap-1 md:flex">
                    <RouterLink
                        v-for="l in primaryLinks"
                        :key="l.to"
                        :to="l.to"
                        class="nav-link"
                        :class="linkClass(l.to)"
                    >
                        {{ l.label }}
                    </RouterLink>

                    <!-- Dropdown -->
                    <div class="relative">
                        <button
                            class="nav-link inline-flex items-center gap-1"
                            :class="openMore ? 'is-active' : ''"
                            @click="toggleMore"
                            :aria-expanded="openMore.toString()"
                            aria-haspopup="menu"
                            aria-controls="more-menu"
                        >
                            More
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.25 8.29a.75.75 0 01-.02-1.08z" clip-rule="evenodd"/>
                            </svg>
                        </button>

                        <transition name="fade">
                            <div
                                v-if="openMore"
                                id="more-menu"
                                role="menu"
                                class="absolute right-0 mt-2 w-64 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg dark:border-gray-800 dark:bg-gray-900"
                            >
                                <RouterLink
                                    v-for="m in moreLinks"
                                    :key="m.to"
                                    :to="m.to"
                                    role="menuitem"
                                    class="block px-4 py-3 text-[16px] text-gray-800 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800"
                                    @click="openMore = false"
                                >
                                    {{ m.label }}
                                </RouterLink>
                            </div>
                        </transition>
                    </div>
                </div>

                <!-- Right actions -->
                <div class="flex items-center gap-2">
                    <!-- Theme toggle: bigger hit area -->
                    <button
                        class="touch-target inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-[16px] font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                        @click="cycleTheme"
                        aria-label="Toggle theme"
                        title="Toggle theme"
                    >
                        <span v-if="mode === 'light'">☀️</span>
                        <span v-else-if="mode === 'dark'">🌙</span>
                        <span v-else>🖥️</span>
                    </button>

                    <!-- CTA (hidden on mobile) -->
                    <RouterLink
                        to="/about"
                        class="hidden rounded-lg bg-indigo-600 px-4 py-2 text-[15px] font-semibold text-white shadow-sm transition hover:bg-indigo-500 active:bg-indigo-700 md:inline-flex"
                    >
                        Get Started
                    </RouterLink>

                    <!-- Mobile toggler: large icon & tap area -->
                    <button
                        class="touch-target inline-flex items-center justify-center rounded-lg p-2 text-gray-800 hover:bg-gray-100 md:hidden dark:text-gray-200 dark:hover:bg-gray-800"
                        @click="openMobile = !openMobile"
                        :aria-expanded="openMobile.toString()"
                        aria-label="Toggle navigation"
                    >
                        <svg v-if="!openMobile" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <svg v-else class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </nav>

            <!-- Mobile panel with bigger text & padding -->
            <transition name="slide">
                <div v-if="openMobile" class="md:hidden">
                    <div class="mt-2 space-y-1 rounded-xl border border-gray-200 bg-white p-2 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <RouterLink
                            v-for="l in [...primaryLinks, ...moreLinks]"
                            :key="l.to"
                            :to="l.to"
                            class="block rounded-lg px-4 py-3 text-[17px] font-medium text-gray-900 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800"
                            :class="route.path === l.to ? 'bg-gray-100 dark:bg-gray-800' : ''"
                            @click="openMobile = false"
                        >
                            {{ l.label }}
                        </RouterLink>
                        <RouterLink
                            to="/about"
                            class="block rounded-lg bg-indigo-600 px-4 py-3 text-center text-[17px] font-semibold text-white shadow-sm transition hover:bg-indigo-500 active:bg-indigo-700"
                            @click="openMobile = false"
                        >
                            Get Started
                        </RouterLink>
                    </div>
                </div>
            </transition>
        </div>
    </header>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useTheme } from '@/composables/useTheme'

const route = useRoute()
const openMobile = ref(false)
const openMore = ref(false)

const primaryLinks = [
    { to: '/', label: 'Home' },
    { to: '/about', label: 'About' },
]
const moreLinks = [
    { to: '/features', label: 'Features' },
    { to: '/pricing', label: 'Pricing' },
]

const linkClass = (to: string) => route.path === to ? 'is-active' : ''

// Theme
const { mode, setTheme } = useTheme()
const cycleTheme = () => {
    if (mode.value === 'light') setTheme('dark')
    else if (mode.value === 'dark') setTheme('system')
    else setTheme('light')
}

// click-outside
const onDocClick = (e: MouseEvent) => {
    const t = e.target as HTMLElement
    if (!t.closest('[aria-controls="more-menu"]') && !t.closest('#more-menu')) openMore.value = false
}
const toggleMore = () => (openMore.value = !openMore.value)

onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<style scoped>
@reference "tailwindcss";

/* Larger “Bootstrap-y” link pill, bigger on mobile, slightly tighter on md+ */
.nav-link {
    @apply rounded-lg px-4 py-3 text-[16px] font-medium text-gray-600 transition
    hover:bg-gray-100 hover:text-gray-900
    dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white
    md:px-3 md:py-2 md:text-[15px];
}
.nav-link.is-active {
    @apply bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-white;
}

/* Transitions */
.fade-enter-active, .fade-leave-active { transition: opacity .12s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.slide-enter-active, .slide-leave-active { transition: all .16s ease; }
.slide-enter-from { opacity: 0; transform: translateY(-4px); }
.slide-leave-to   { opacity: 0; transform: translateY(-4px); }
</style>
