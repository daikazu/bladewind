// The content file lists every candidate the fixture needs; nothing else is scanned. Class-strategy
// dark mode is what produces the `:is(.dark .dark\:bg-black)` selector shape the driver's targeted
// token reading exists for.
module.exports = {
    content: ['./content.html'],
    darkMode: 'class',
    theme: { extend: {} },
    plugins: [],
};
