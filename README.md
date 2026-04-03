# Preview Generator

Nextcloud app that allows users to generate stl previews. The app listens to 
upload, edit and retrieve events and generates or updates an preview on demand.

The app does not replace the existing on demand preview generation.

## Currently supported file formats

* `model/stl`
* `model/obj`
* `model/3mf`

## How to install

* Clone this repository into your Nextcloud app folder

## How to use the app

1. Install the app
2. Enable the app

## Known issues

* Repository is not yet signed to install as a trusted plugin

## How does the app work

1. Listen to events that a file has been written, modified or accessed
2. Creates or updates a thumbnail preview once every event if it is not existent

## FAQ

### I want to skip a folder and everything in/under it

Add an empty file with the name `.nomedia` in the folder you wish to skip. All files and subfolders of the folder containing `.nomedia` will also be skipped.

### I want to reset/regenerate all previews

**WARNING:** This is not supported but it has been confirmed to work by multiple users. Proceed at your own risk. Always keep backups around.

1. Remove the folder `your-nextcloud-data-directory/appdata_*/preview`
2. *Optional:* change parameters `preview_max_x` and `preview_max_y` in `config.php` (e.g., to `512`), and change the `previewgenerator` app parameters `heightSizes`, `squareSizes` and `widthSizes` as per the README (or better yet, to a low value each, e.g. `512`, `256` and `512` respectively)
3. Run `occ files:scan-app-data` (this will reset generated previews in the database)
4. Run `occ preview:generate-all [user-id]` (this will run very fast if you did step 2) 
