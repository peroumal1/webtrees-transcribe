# webtrees-transcribe

A [webtrees](https://webtrees.net) module that transcribes historical genealogical documents using [Claude AI](https://anthropic.com).

Upload a scan of a parish register, civil record, notarial act, or any handwritten document — the module extracts names, dates, places, and family relationships and presents them alongside the individual's record.

## Requirements

- webtrees 2.2+
- PHP 8.2+
- An [Anthropic API key](https://console.anthropic.com)

## Installation

1. Download or clone this repository into your webtrees `modules_v4/` directory:
   ```
   cd /path/to/webtrees/modules_v4
   git clone https://github.com/peroumal1/webtrees-transcribe
   ```

2. Install dependencies:
   ```
   cd webtrees-transcribe
   composer install --no-dev
   ```

3. In webtrees, go to **Control Panel → Modules → Tabs** and enable **Transcribe**.

4. Click **Configure** and enter your Anthropic API key.

## Usage

Open any individual record in webtrees. A **Transcribe** tab appears alongside Facts, Relations, etc. Upload a document image — the module sends it to Claude and displays the extracted text, names, dates, places, and relationships.

## Pricing

Document transcription uses the Claude API, billed per token by Anthropic. A typical one-page document costs approximately $0.003–$0.01. You supply your own API key.

## License

GPL-3.0-or-later — same as webtrees.
