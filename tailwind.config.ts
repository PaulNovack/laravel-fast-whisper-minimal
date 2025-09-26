/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',  // IMPORTANT
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.{vue,js,ts}',
    ],
    theme: { extend: {} },
    plugins: [],
}
