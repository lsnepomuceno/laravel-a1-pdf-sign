const { defineConfig } = require('@vue/cli-service')
module.exports = defineConfig({
    publicPath: process.env.NODE_ENV === 'production'
        ? '/laravel-a1-pdf-sign'
        : '/',
    transpileDependencies: true,
    pluginOptions: {}
})
