# Preview Provider STL

Nextcloud app that generates thumbnail previews for 3D model files. The app listens to
upload, edit and retrieve events and generates or updates a preview on demand.

The app does not replace the existing on demand preview generation.

## Supported File Formats

- `model/stl` (Stereolithography)
- `model/obj` (Wavefront OBJ)
- `model/3mf` (3D Manufacturing Format)

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

The bundled `stl-thumb` binary is included - no additional system packages needed.

## Usage

### Automatic Previews

Previews are generated automatically when users view 3D files in Nextcloud.

### Generate Previews Manually

Use the OCC command to generate previews:

```bash
# Generate preview for a specific file by ID
occ preview:generate-stl --file-id=12345

# Generate previews for all STL files of a user
occ preview:generate-stl username

# Generate previews for a specific folder
occ preview:generate-stl username --path="/Documents/3D"

# Generate previews for ALL users
occ preview:generate-stl

# Verbose output
occ preview:generate-stl -v
```

## How It Works

1. When a user views a 3D model file, Nextcloud's preview system requests a thumbnail
2. The STL preview provider handles the request
3. It uses the bundled `stl-thumb` binary to render the 3D model as an image
4. The image is scaled to fit the requested dimensions and returned
