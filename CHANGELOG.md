# Changelog

All notable changes are written here, newest first, in the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format. A release's section is also its release notes on GitHub.

## [Unreleased]

## [0.1.2] - 2026-11-09

### Added

- `AgentClient` can keep batches on disk while the agent is away, for example restarting in a deploy, and sends them after its next successful send.

## [0.1.1] - 2026-11-09

### Added

- The agent learns from the heartbeat which event types the server knows. An event of an unknown type is dropped, and at most once an hour per type the agent reports an `UnsupportedFeature` exception, so the project gets an issue saying to update the server.
- Custom alerts are passed on in batches of their own, so a server that refuses one does not cost the rest of the data.

## [0.1.0] - 2026-11-05

First public release, split out of `laravel-lumberjack`.

[Unreleased]: https://github.com/ExeQue/lumberjack-agent/compare/v0.1.2...HEAD
[0.1.2]: https://github.com/ExeQue/lumberjack-agent/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/ExeQue/lumberjack-agent/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ExeQue/lumberjack-agent/releases/tag/v0.1.0
