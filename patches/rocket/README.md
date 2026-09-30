# Rocket patches

`frontend/js/datastar-rocket.js` is the Datastar v1.0.4 + Rocket beta.2 release with these patches applied,
built by `scripts/vendor-rocket.sh`. The patch files are Starbase's `patches/rocket/`, copied unchanged. The
script is our own: where Starbase's clones Datastar and runs `git am`, it applies the patches with patch(1) to
`node_modules/datastar` and builds offline with the same esbuild version and flags, so the bundle is byte for
byte the one Starbase serves from `static/vendor/datastar-rocket.js`. Only the `.map` differs: ours records
stable relative paths and embeds the TypeScript sources, so DevTools can show them. `rocket.lock.json` records the Starbase commit, the bundle's banner and sha256,
and each patch's sha256; `tests/Unit/FrontendAssetsTest.php` checks them.

| Patch | Upstream issue |
|---|---|
| 0001 finish the teardown when deleting the signals throws | [#1217](https://github.com/starfederation/datastar/issues/1217) |
| 0002 keep the element as it is on an atomic move | [#1218](https://github.com/starfederation/datastar/issues/1218) |
| 0003 form-associated components and focus delegation | [#1220](https://github.com/starfederation/datastar/issues/1220) |
| 0004 bool props follow HTML boolean attributes | [#1219](https://github.com/starfederation/datastar/issues/1219) |
| 0005 clean up the shadow tree's attributes on disconnect | [#1221](https://github.com/starfederation/datastar/issues/1221) |
| 0006 instances share one constructed stylesheet per CSS text | [#1222](https://github.com/starfederation/datastar/issues/1222) |
| 0007 observers hear an attribute write whose value decodes the same | [#1223](https://github.com/starfederation/datastar/issues/1223) |
| 0008 a morph that starts inside another keeps the outer one's pantry and id maps | [#1209](https://github.com/starfederation/datastar/issues/1209) |
| 0009 a light component renders inside a data-ignore-morph ancestor | not filed yet: [draft](https://github.com/zweiundeins/starbase/blob/main/docs/upstream/09-render-inside-ignore-morph.md) |
| 0010 a removed element's mount root leaves the observed roots | not filed yet: [draft](https://github.com/zweiundeins/starbase/blob/main/docs/upstream/10-removed-elements-stay-observed.md) |
| 0011 a queued definition applies the shadow host's children that Datastar's first pass skipped | not filed yet: [draft](https://github.com/zweiundeins/starbase/blob/main/docs/upstream/11-queued-definition-children.md) |
| 0012 the pending-host observer scans a parent once per batch, not once per moved child | not filed yet: [draft](https://github.com/zweiundeins/starbase/blob/main/docs/upstream/12-pending-host-observer-rescan.md) |

```sh
sh scripts/vendor-rocket.sh --check                   # the committed bundle is the build (offline)
sh scripts/vendor-rocket.sh --if-tools                # postinstall: rebuild, or warn and keep the committed bundle
sh scripts/vendor-rocket.sh --from ../starbase --ref <commit>   # take a new patch set from Starbase
```

The build refuses a result whose sha256 differs from Starbase's bundle at the recorded commit. Under
`--if-tools` it warns instead and keeps the committed bundle, so `pnpm install` still works without esbuild
or patch(1) and right after a pin bump. When a Datastar release contains a fix, Starbase drops that patch
first; then bump `package.json`, run `pnpm install` (it warns that the build differs) and take the new set
with `--from`. Once no patch is left, load the release's `bundles/datastar-rocket.js`
directly and delete this folder.
