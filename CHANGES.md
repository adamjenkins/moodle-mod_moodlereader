# Changelog — mod_moodlereader

The plugin is in pre-release development. The version stays at `0.0.z` until a
`1.0.0` release is explicitly cut; `x`/`y` are not bumped without sign-off.

## [0.0.3] - 2026-10-04

- The plugin's maturity is now Beta (it was Alpha).
- composer.json now requires `moodle/moodle` `^5.2` rather than `>=5.2 <5.4`, so
  later Moodle 5.x releases are no longer excluded.
- Continuous integration now tests against the released Moodle 5.3
  (`MOODLE_503_STABLE`) instead of Moodle's development branch.
