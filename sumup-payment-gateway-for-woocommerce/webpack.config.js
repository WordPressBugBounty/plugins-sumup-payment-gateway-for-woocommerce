const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
  ...defaultConfig,
  entry: {
    index: './src/index.js',
    settings: './src/js/settings.js',
    'sumup-gateway': './src/js/sumup-gateway.js',
    'sumup-process-checkout': './src/js/sumup-process-checkout.js',
  },
  optimization: {
    ...defaultConfig.optimization,
    splitChunks: false,
    runtimeChunk: false,
  },
};
