## What and why

<!-- One concern per PR. Say the effect, and what in the protocol required it. -->

## Checklist

- [ ] `composer preflight` is green (fixers, format check, `vue-tsc`, PHPStan, full Pest suite)
- [ ] New federation behaviour carries its conformance-grouped test (`->group('FP-7')`), or a bug fix carries the test that failed before it
- [ ] Spec citation in the docblock at the point of implementation, where a normative rule is being implemented
- [ ] Schema or query changes verified on MySQL too (`vendor/bin/pest --configuration=phpunit.mysql.xml`)
- [ ] User-facing strings go through translation keys, not hardcoded text
- [ ] Authorization is permission-based, with no `hasRole()` checks
