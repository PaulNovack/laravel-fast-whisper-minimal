<template>
    <div class="min-h-screen bg-white text-gray-900 dark:bg-gray-900 dark:text-gray-100">
        <NavBar />
        <main class="mx-auto max-w-6xl px-4 py-8">
            <RouterView />
        </main>
        <footer class="border-t border-gray-200 py-6 text-center text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
            Whisper ASR / VAD Demo
        </footer>
    </div>
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router'
import NavBar from './components/NavBar.vue'
</script>
