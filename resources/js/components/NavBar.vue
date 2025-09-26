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
                    <span class="text-[18px] font-semibold tracking-tight sm:text-[16px]">Whisper ASR / VAD Demo</span>
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


                        <transition name="fade">
                            <div
                                v-if="openMore"
                                id="more-menu"
                                role="menu"
                                class="absolute right-0 mt-2 w-64 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg dark:border-gray-800 dark:bg-gray-900"
                            >
                            </div>
                        </transition>
                    </div>
                </div>

                <!-- Right actions -->
                <div class="flex items-center gap-2">


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
                            v-for="l in [...primaryLinks]"
                            :key="l.to"
                            :to="l.to"
                            class="block rounded-lg px-4 py-3 text-[17px] font-medium text-gray-900 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800"
                            :class="route.path === l.to ? 'bg-gray-100 dark:bg-gray-800' : ''"
                            @click="openMobile = false"
                        >
                            {{ l.label }}
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


const linkClass = (to: string) => route.path === to ? 'is-active' : ''



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
