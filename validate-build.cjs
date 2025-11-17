const fs = require('fs');

console.log("🔍 Validating build configuration...");

const requiredFiles = [
  'src/js/sparxstar-collector.js',
  'src/js/sparxstar-state.js',
  'src/js/sparxstar-profile.js',
  'src/js/sparxstar-sync.js',
  'src/js/sparxstar-ui.js',
  'src/js/sparxstar-integrator.js',
  'src/css/sparxstar-user-env-check.css'
];

let ok = true;

for (const file of requiredFiles) {
  if (!fs.existsSync(file)) {
    console.log(`❌ Missing: ${file}`);
    ok = false;
  } else {
    console.log(`✅ ${file}`);
  }
}

if (!ok) {
  console.log("⚠️ Validation failed.");
  process.exit(1);
}

console.log("🎉 Validation complete!");
process.exit(0);