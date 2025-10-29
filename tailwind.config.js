/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./index.php",
    "./assets/js/**/*.js",
    "./**/*.php",
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: "#0a6cf1",
          dark: "#0b3fad",
          light: "#39bdf4",
        },
        slateink: "#1d2a44",
        slatealt: "#425170",
      },
      fontFamily: {
        sans: ["Poppins", "Arial", "sans-serif"],
      },
      boxShadow: {
        hero: "0 2px 8px rgba(0,0,0,0.08)",
        card: "0 12px 24px rgba(11, 63, 173, 0.08)",
        panel: "0 20px 48px rgba(13, 54, 115, 0.12)",
        intro: "0 18px 40px rgba(11, 63, 173, 0.1)",
      },
    },
  },
  plugins: [
    require("@tailwindcss/forms"),
  ],
}

