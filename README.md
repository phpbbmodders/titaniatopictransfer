# Titania Topic Transfer

[![Tests](https://github.com/phpbbmodders/titaniatopictransfer/actions/workflows/tests.yml/badge.svg)](https://github.com/phpbbmodders/titaniatopictransfer/actions/workflows/tests.yml)
[![Lint](https://github.com/phpbbmodders/titaniatopictransfer/actions/workflows/lint.yml/badge.svg)](https://github.com/phpbbmodders/titaniatopictransfer/actions/workflows/lint.yml)

Transfer phpBB forum discussions to Titania contribution support topics.

## Features

Moderators can move selected forum replies from Quick-mod to a new or existing Titania support topic.
- Move whole topics and leave a locked forum link to the Titania destination.
- Copy topics and close the originals with a moderator reason and destination link.
- Transfer supported attachments and optionally carry subscriptions and read markers to Titania.
- Optionally notify the topic starter by private message.

## Requirements

- phpBB 4.0.x
- PHP 8.2 or newer
- A working Titania installation on the same board
  The disposable phpBB 4.0 test board used local Titania compatibility fixes.

## Installation

Copy the extension to `ext/phpbbmodders/titaniatopictransfer/` and enable **Titania Topic Transfer** under **ACP → Customise → Manage extensions**.

## Contributing

- [Report a bug](https://github.com/phpbbmodders/titaniatopictransfer/issues).
- [Ask questions or propose features](https://github.com/phpbbmodders/titaniatopictransfer/discussions).

## Acknowledgments

- The archived phpBB Move Topic to Titania MOD inspired the workflow.
- Codex assisted with implementation and testing.

## License

Licensed under the GNU General Public License, version 2. See [license.txt](license.txt).
