# Changelog

## 1.1.0 – 2026-04-21
### Added
- MIME type registration repair step (no longer requires 3D Viewer app)
- Bundled stl-thumb binary (works in Docker without system installation)
- `preview:generate-stl` OCC command for generating thumbnails

### Changed
- Use bundled stl-thumb binary from vendor directory
- Improved error handling and logging in preview generation
- Added timeout (30s) to prevent hanging requests
- Security: validate custom binary path to prevent path traversal

### Removed
- Unused vendor files (reduced package size)

## 1.0.0 – 2025-01-06
Initial release
