# device-trust-client.js — provenance

Derived artifact. Do not edit by hand.

| Field | Value |
| --- | --- |
| Source repository | `Starisian-Technologies/parxstar-3iatlas-device-trust-node` |
| Source entry | `src/client/browser.ts` |
| Source commit | `b115088d4ddbf3c7fb1f8845bf20d248df2c1bd2` (branch `ccr-3a3dfbf3-c8ikuv`) |
| Build command | `pnpm run build` (`wp-scripts build device-trust-client=./src/client/browser.ts`) |
| Output | `assets/browser/device-trust-client.js` |
| sha256 | `10c31b7befb4e0110698834f5c05ee9b15cf6af763a4b3a5e6488209f6b90f98` |
| Contract | `sparxstar.device-trust/v1` |

To update: rebuild in the Device Trust Node at the new commit, copy the file
here, and update this table and `CLIENT_VERSION` in
`src/adapters/DeviceTrustAdapter.php` in the same PR.
`tests/unit/DeviceTrustAdapterTest.php` fails if the sha256 above does not
match the file.
