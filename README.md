# YDown - Embedded Video and MP3 Downloader

YDown is a PHP-based downloader UI for embedded media sources.  
It supports two fully independent download slots that run in parallel without a global queue.

## Current Features

- Two parallel download slots (`Slot A` and `Slot B`)
- Automatic URL scan on paste (or manual scan with Enter)
- MP3 and MP4 download modes
- YouTube handling via `yt-dlp`
- MP3 conversion via `ffmpeg` (`libmp3lame`)
- Worker-based background processing per job
- Live progress polling (`api/status.php`)
- File result endpoint (`api/result.php`)
- Per-slot persistent preferences (format, bitrate/resolution, encoding speed)
- Per-slot "Final file" summary line that clearly states expected output
- Job List modal with plain-text URL intake (one URL per line)
- Event-driven auto scheduler that assigns pending jobs to free slots
- Failed-job handling (`Retry`, `Remove`, `Clear failed`)
- Persistent Job List defaults (`format`, `MP3 bitrate/speed`, `MP4 resolution/profile`)
- Job List `Default output` as the single batch-output selector (`Audio (MP3)` or `Video (MP4)`)
- Job List shows only relevant profile controls for the selected output type
- Duplicate URL confirmation within the same browser session
- Theme support (`light` / `dark`) with automatic system preference detection
- Automatic cache busting for JS assets based on file modification time

## Current Defaults

- Default target format: `mp3`
- Default MP3 bitrate: `320 kbps`
- Default MP3 encoding speed: `High quality (balanced)` (`compression_level=2`)

## Architecture Summary

- `index.php` renders UI and handles action routing (`analysieren_ajax`, `start_worker_job`, etc.).
- Each download starts a separate worker job (no serialized queue model).
- Frontend polls `api/status.php?job=<id>` for realtime status.
- When ready, frontend triggers `api/result.php?job=<id>` for file delivery.
- Internal worker HTTP trigger is localhost-only: `api/internal/worker_job_http.php`.
- CLI worker runner: `cli/worker_job_runner.php`.

## Project Structure

```text
tools/ydown/
|- api/
|  |- status.php
|  |- result.php
|  `- internal/
|     `- worker_job_http.php
|- bin/
|- cli/
|  `- worker_job_runner.php
|- docs/
|- js/
|  |- ydown-namespace.js
|  |- config.js
|  |- app-controller.js
|  |- main.js
|  |- core/
|  |  |- storage-service.js
|  |  |- url-utils.js
|  |  `- base-utils.js
|  |- services/
|  |  |- api-client.js
|  |  |- job-list-store.js
|  |  |- job-scheduler.js
|  |  |- progress-mapper.js
|  |  `- download-history-service.js
|  |- slots/
|  |  `- slot-controller.js
|  `- ui/
|     |- job-list-modal-controller.js
|     |- modal-controller.js
|     `- theme-controller.js
|- lib/
|  |- FileDownloader.php
|  |- ProcessStatusStore.php
|  |- TempPath.php
|  |- VideoFormatDetector.php
|  |- ViewHelpers.php
|  |- WorkerJobRunner.php
|  |- WorkerJobStore.php
|  `- YtDlpTool.php
|- tests/
|  `- playwright_progress_check.mjs
|- tmp_runtime/
|- index.php
|- install-tools.ps1
|- install-tools.bat
|- README.md
`- LICENSE
```

## Requirements

- PHP 8.x with common extensions for this project (`curl`, `json`, `mbstring`, DOM/XML support)
- `ffmpeg` for MP3 conversion and HLS-related processing
- `yt-dlp` for YouTube extraction
- Node.js (optional, only for Playwright-based tests)

Binary lookup order is project-local first, then environment variables, then system PATH.

- `FFMPEG_PATH` can override ffmpeg path.
- `YTDLP_PATH` can override yt-dlp path.

## Installation

### PowerShell

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\ydown\install-tools.ps1
```

Optional flags:

- `-OnlyYtDlp`
- `-OnlyFfmpeg`
- `-Force`

### BAT

```bat
ydown\install-tools.bat
```

Optional flags:

```bat
ydown\install-tools.bat -OnlyYtDlp
ydown\install-tools.bat -OnlyFfmpeg
ydown\install-tools.bat -Force
```

## Usage

1. Open `http://******/ydown/`
2. Paste URL(s) into `Video URL 1` and/or `Video URL 2`
3. Wait for scan completion
4. Select preferred quality and format per slot
5. Start one or both downloads

Optional Job List workflow:

1. Click `Jobs` (top-right) to open the modal.
2. Paste multiple URLs (one per line) and click `Add jobs`.
3. Choose `Default output` in Job List (`Audio (MP3)` or `Video (MP4)`).
4. Confirm the visible profile controls for that output type, then click `Start auto`.
5. The scheduler assigns jobs to free slots automatically.
6. Completed jobs are removed automatically; failed jobs stay visible for retry/removal.

Output clarity rules:

- Main window does not contain a global batch output selector.
- Job List `Default output` defines batch output intent.
- Slot panels show `Final file: ...` summary for immediate per-slot output clarity.

Start button states are intentionally contextual:

- `Enter URL` when the field is empty
- `Scan URL` when URL exists but scan is not ready yet
- `Processing MP3...` / `Processing MP4...` during scan/download processing
- `Download MP3` / `Download MP4` when ready to start

## Testing

Run the current Playwright progress smoke test:

```powershell
node tests/playwright_progress_check.mjs
```

## Cache Busting

Static assets are versioned with `filemtime` through:

- `lib/ViewHelpers.php`
- helper: `auto_version(string $file): string`

`index.php` uses this helper for JS script URLs so clients automatically refresh changed assets without manual hard reload.

## Notes

- YouTube may sometimes return anti-bot responses depending on IP, network, or video.
- Always download content only when you have the legal rights to do so.

## License

This project is licensed under the MIT License.  
See `LICENSE` for full text.

## Changelog

See `CHANGELOG.md` for release history and notable updates.
