var webpack = require('webpack');
var path = require('path');
var ExtractTextPlugin = require("extract-text-webpack-plugin");
var CleanWebpackPlugin = require('clean-webpack-plugin');

var nodeEnv = process.env.NODE_ENV || 'development';

var entryDir = './upload/catalog/webpack/';
var outputDir = './upload/catalog/view/dist/';


module.exports = {
    mode: nodeEnv,
    entry: {
        main: [
            entryDir + 'main.js',
            entryDir + 'main.scss'
        ]
    },
    output: {
        filename: '[name].js',
        path: path.resolve(__dirname, outputDir)
    },
    module: {
        rules: [
            {
                test: /\.s[ac]ss$/,
                use: ExtractTextPlugin.extract({
                    use: [
                        {
                            loader: 'css-loader',
                            options: {
                                minimize: true
                            }
                        },
                        'sass-loader'
                    ],
                    fallback: 'style-loader'
                })
            },
            {
                test: /\.js$/,
                exclude: /node_modules/,
                loader: "babel-loader"
            }
        ]
    },
    plugins: [
        new CleanWebpackPlugin(outputDir, {
            root: __dirname,
            verbose: true,
            dry: false
        }),
        new ExtractTextPlugin('[name].css')
    ]

};
