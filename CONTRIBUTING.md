# Contributing

Keep a change small and covered by tests. CI checks the formatting, runs static analysis, runs the suite on PHP 8.4 and 8.5, and checks that the agent still runs on PHP 8.3.

```bash
composer install
composer test       # the suite (Pest 5, PHP 8.4 or newer)
composer analyse    # PHPStan, level 7
composer lint       # format the code
composer lint:test  # check the formatting, as CI does
```

The package has one runtime dependency, Guzzle. Keep it that way: anything else goes in `require-dev`.

## Pull requests

- For anything bigger than a bug fix, open an issue first so we can agree on the approach.
- Security problems go through [SECURITY.md](SECURITY.md), never a public issue.
- Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages and the pull request title, e.g. `fix(spool): keep the oldest batch when the disk is full`.
- A change to what the agent accepts or sends updates the README in the same pull request.
- By contributing you agree that your work is released under the [MIT License](LICENSE).
