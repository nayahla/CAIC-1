/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./templates/**/*.twig",
    "./src/**/*.{js,ts,jsx,tsx}"
  ],
  theme: {
    extend: {
      colors: {
        navy: "#10232F",
        charcoal: "#1A1A1A",
        slate: "#4A4F52",
        grey: "#D9DCE0",
        offwhite: "#F7F9FA",
        red: "#E85B57",
        blue: "#3BB8E2",
        teal: "#2CA88F",
        amber: "#F2A541",
      }
    }
  },
  plugins: [],
}
