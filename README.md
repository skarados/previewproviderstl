# Preview Provider STL

Nextcloud app that generates thumbnail previews for 3D model files. The app listens to
upload, edit and retrieve events and generates or updates a preview on demand.

The app does not replace the existing on demand preview generation.

## Supported File Formats

- `model/stl` (Stereolithography)
- `model/obj` (Wavefront OBJ)
- `model/3mf` (3D Manufacturing Format)

## Features

- Generates thumbnails on-demand via Nextcloud's preview system
- Uses bundled `stl-thumb` binary (with system binary fallback)
- OCC command for batch preview generation
- File size limit: 250MB

## Requirements

- Nextcloud 29-32
- PHP 7.4+

## Installation

1. Clone this repository into your Nextcloud `custom_apps` folder:
   ```bash
   cd /var/www/nextcloud/custom_apps
   git clone https://github.com/skarados/previewgeneratorstl.git
   ```
2. Enable the app:
   ```bash
   occ app:enable previewproviderstl
   ```

## Usage

### Automatic Previews

Previews are generated automatically when users view 3D files in Nextcloud.

### OCC Command

```bash
# Generate preview for a specific file by ID
occ preview:generate-stl --file-id=12345

# Generate previews for all STL files of a user
occ preview:generate-stl username

# Generate previews for a specific folder
occ preview:generate-stl username --path="/Documents/3D"

# Generate previews for ALL users
occ preview:generate-stl

# Force regenerate (delete existing previews first)
occ preview:generate-stl --force
```

## Binary

The app uses the `stl-thumb` binary to render thumbnails. It prefers:
1. `/usr/bin/stl-thumb` (system binary if installed)
2. Bundled binary in `vendor/stl-thumb-bin/stl-thumb`

### Headless Servers

For headless Nextcloud installations (no display), you may need to install:
- `xvfb` package, OR
- X11 libraries: `libxcursor1 libxrandr2 libxinerama1`

## How It Works

1. When a user views a 3D model file, Nextcloud's preview system requests a thumbnail
2. The STL preview provider passes the file to `stl-thumb`
3. `stl-thumb` renders the 3D model as an image using OpenGL
4. The image is scaled to fit the requested dimensions