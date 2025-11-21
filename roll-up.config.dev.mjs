import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import json from '@rollup/plugin-json';

export default {
    input: 'src/js/sparxstar-bootstrap.js',

    output: {
        file: 'assets/js/sparxstar-user-environment-check-app.bundle.min.js',
        format: 'iife',
        name: 'SparxstarUserEnvironmentCheckApp',
        sourcemap: true
    },

    plugins: [
        json(),
        resolve({
            browser: true,
            preferBuiltins: false
        }),
        commonjs()
        // No terser for dev builds - much faster!
    ]
};
