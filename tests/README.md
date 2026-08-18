# Scanner regression tests

These are browser-level regression tests for the Clash Cards scanner.

## Why browser regression tests?

`card-scanner.js` uses Canvas, image decoding, Tesseract.js, localStorage and DOM
state. A headless real browser tests the same pipeline the users actually run.

## First setup

From the project root:

    npm install
    npx playwright install chromium

## Run all scanner fixtures

    npm run test:scanner

Every directory under `tests/fixtures/` that contains an `expected.json` is
automatically discovered and tested.

## Add another known-good dataset

Create:

    tests/fixtures/PLAYER-DEVICE-DESCRIPTION/

Put the screenshots in that directory and add an `expected.json`.

No test-code change is required.

IMPORTANT: expected.json must contain HUMAN-VERIFIED quantities. Do not create
the expected file from the scanner's own output, because that would simply
bless scanner mistakes as correct.

## Failure output

A failure prints lines like:

    Elixir | Archer: expected 3, detected 2
    Super | Ice Hound: expected 2, detected 3

The complete scanner debug output is also printed, and Playwright keeps a trace
under `test-results/` for failed tests.

## CI

The included GitHub Actions workflow runs this regression suite on pushes and
pull requests affecting the scanner or test fixtures.

Because Tesseract.js is currently loaded from jsDelivr, the runner needs normal
internet access.
