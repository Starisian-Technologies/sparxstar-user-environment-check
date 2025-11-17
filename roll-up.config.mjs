import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import terser from '@rollup/plugin-terser';

export default {
    input: 'src/js/starmus-integrator.js',

    output: {
        file: 'assets/js/sparxstar-user-environment-check-app.bundle.min.js',
        format: 'iife',
        name: 'SparxstarUserEnvironmentCheckApp',
        sourcemap: false
    },

    plugins: [
        resolve(),
        commonjs(),
        terser()
    ]
};
