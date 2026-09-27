import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    DEFAULT: '#4f46e5',
                    hover: '#4338ca',
                    bg: '#eef2ff',
                },
                status: {
                    good: '#0ca30c',
                    'good-bg': '#e6f6e6',
                    warning: '#fab219',
                    'warning-bg': '#fef3e0',
                    critical: '#dc2626',
                    'critical-bg': '#fde8e8',
                },
            },
        },
    },

    plugins: [forms],
};
