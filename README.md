# fmradius2
A simple PHP-based Web service to map and search for United States FM radio stations.  A live demo is available at https://fmradi.us.

## Requirements
* A Web host that can serve files and invoke PHP to process .php files
* PHP must have the PDO module available
* Optional: Access to a [PDO-compatible database](https://www.php.net/manual/en/pdo.drivers.php) (SQLite is also an option)

## Setup Instructions
1. Download the repo and navigate to the `dist` folder.  This is the "public" (Web-accessible) folder.
2. Retrieve a FCC **FM Query** in *"Text file (pipe delimited, no links)"* format from: https://www.fcc.gov/media/radio/fm-query and save the resulting file in `scripts/fccdata`.  (You do not need to select any special options on this page.)
3. Retrieve **FM Service Contours** data from the FCC here: https://transition.fcc.gov/Bureaus/MB/Databases/fm_service_contour_data/ and extract the archive to `scripts/fccdata` as well.  This text file is approximately 200 MB.
4. Edit `scripts/config.php` to set up your database information.  You can use any [PDO-compatible database](https://www.php.net/manual/en/pdo.drivers.php), including SQLite if you don't wish to set up a permanent database.
5. Copy the `dist` folder to your PHP-enabled Web host, in the "public" location (for example, `/var/www/html`).
6. In your browser, navigate to *.../scripts/loaddb.php* (for example, https://localhost:8080/scripts/loaddb.php - or replace localhost:8080 with your domain name).  This will load the tables.
7. Test the site by navigating to the site root.

## Using the Site
The search box can be used to search for:
* A state or territory (like `TX`, `DC` or `PR`)
* A frequency like `96.7`
* A callsign like `WRNR`
* A city name to search for the registered city, like `Raleigh`
* A combination of the above, for example `103.5 WA` or `Rome NY`
* Leave the box blank to search for all services.

### Searching in Specific Areas
If you want to lock in to a specific area, make sure to check the *Only in view* box.  Otherwise your search may be expanded to the whole jurisdiction.

By default the site will try searching within the boundary of the map that is on your screen.  If it cannot find any results it will try searching the whole jurisdiction.

### FM Service Types
Not all FCC-registered services are "full service" FM radio stations.  If you want to search for other kinds of services, you may do so by unchecking the "Only standard FM service" box to show all registered services.  You can also use one of these in your query:
* FM - Standard FM radio stations
* FX - FM Translators
* FB - FM Boosters
* FS - Auxiliary/Backup

Note: the default *"Only standard FM service"* option also includes the service type FL, which indicates a low-power (LP) station.

## Notes
* `.htaccess` files are automatically included to prevent Apache2 from serving files from `/scripts/fccdata` as they can be large.
  * If you are running nginx or another Web server, configure it to deny access to `/scripts/fccdata`, or simply delete that folder.
* After setup, make sure to check your server's setup for certificates, HSTS, and Content Security Policy.
* U.S. territories, like Puerto Rico, are included in the FCC's data.  (Try the queries "PR FM" and "PR")
* The default limit for results is 300 stations and 50 service contours.

### Ideas for improvements
* Make an Apache-compatible Content Security Policy file for setup steps.
* Add a geolocator and logic to invoke it.
* Option to toggle service contours.
