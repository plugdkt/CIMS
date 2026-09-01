/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/Livewire/**/*.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                display: ['"Bai Jamjuree"', '"Noto Sans Thai"', 'system-ui', 'sans-serif'],
                sans: ['Sarabun', '"Noto Sans Thai"', 'system-ui', 'sans-serif'],
                mono: ['"IBM Plex Mono"', 'ui-monospace', 'Sarabun', 'monospace'],
            },
            colors: {
                // Purple/gold palette approved for CMIS — see the UI concept artifact.
                // Gold is a functional accent (highlights/emphasis), not decorative.
                bg: '#F8F5FC',
                surface: '#FFFFFF',
                'surface-alt': '#F1E7FB',
                border: '#E1D2F2',
                ink: {
                    DEFAULT: '#1E1330',
                    muted: '#524469',
                    faint: '#85769D',
                },
                accent: {
                    DEFAULT: '#7C1FD1',
                    strong: '#63189F',
                    soft: '#F0E0FC',
                    'soft-ink': '#6A17B8',
                },
                gold: {
                    DEFAULT: '#C6941E',
                    soft: '#FBF0D7',
                    ink: '#8A6812',
                },
                success: {
                    DEFAULT: '#1F7A4C',
                    soft: '#DFF3E7',
                    ink: '#186339',
                },
                warning: {
                    DEFAULT: '#B4571A',
                    soft: '#FBE9D8',
                    ink: '#8C4212',
                },
                danger: {
                    DEFAULT: '#C22B3D',
                    soft: '#FCE1E4',
                    ink: '#9C1F2E',
                },
                neutral: {
                    soft: '#EFEAF6',
                    ink: '#5C5470',
                },
            },
        },
    },
    plugins: [],
};
