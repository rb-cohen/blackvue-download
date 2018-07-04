# BlackVue Download

PHP script to download BlackVue dashcam video files to local directory.

DashCam must be connected to LAN (over WiFi), tested with BlackVue 750s.

Videos that already exist in the local directory will not be overwritten by default, allowing this script to be run often without fear of excessive bandwidth usage.
 
## Installation

Requires PHP5.3+

1. Clone this git repository
2. Run `$ composer install` to install dependencies (http://getcomposer.org)

## Usage

Example `$ php bin/download.php --ip 192.168.0.10 --directory /path/to/download`

### Arguments

**-- ip** - The IP address of your dashcam on the network

**--directory** - The path to download video files to on the local computer