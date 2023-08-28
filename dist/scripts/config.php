<?php
// Save error reporting settings and disable to avoid risk of exposure of
// sensitive values.
$current_error_reporting = error_reporting(0);

// fmradius Configuration File
//
//
// ================================= Required Settings ======================
//
// Database connection information.  Used to create a PHP PDO object.
// If a database is not available, you can configure an SQLite database.
// See https://www.php.net/manual/en/pdo.drivers.php for a list of
//   supported databases and instructions for setup.
define('DB_DRIVER',   "mysql");
define('DB_HOST',     "localhost");
define('DB_PORT',     "3386");
define('DB_DBNAME',   "fmradius");
define('DB_USERNAME', "fmradius");
define('DB_PASSWORD', "fmradius");

// Locations of data files retrieved from the FCC, relative to the <website root>/scripts folder.
define('FCCDATA_DIR', 'fccdata/');
define('FCCDATA_FMQ_FILE', 'fmq.txt');
define('FCCDATA_CONTOURS_FILE', 'FM_service_contour_current.txt');


// ================================= Optional Settings ======================
// The following settings generally do not need to be changed.
//
// Schema for the FM Query output file.  Retrieved from
//   https://www.fcc.gov/media/radio/am-fm-tv-textlist-key
define('FCCDATA_FMQ_SCHEMA', ["unused0", "callsign", "frequency", "service_type", "channel", "antenna_type", "unused1", "fm_station_class", "unused2", "license_status", "city", "state", "country", "file_number", "power_h", "power_v", "antenna_haat_h", "antenna_haat_v", "facility_id", "lat_ns", "lat_deg", "lat_min", "lat_sec", "lon_ew", "lon_deg", "lon_min", "lon_sec", "licensee", "", "", "", "rcamsl_h", "rcamsl_v", "directional_antenna_id", "directional_antenna_angle", "antenna_structure_registration_number", "antenna_radiation_agl", "application_id", "lms_application_id"]);
	 
// Special parsing instructions for fields:
//   first_word: record first space-separated token (ex: " 107.9   MHz" -> "107.9")
//   service_contours: special parsing that ends normal parsing for the line and
//                     transforms "|lat,lon|...|^|" into "[[lat,lon]...]"
//                     of the line as json lat/long pairs
define('FCCDATA_SPECIAL_PARSING', ["frequency" => "first_word"]);

// Retrieved from service contour file header.
define('FCCDATA_CONTOUR_SCHEMA',  ["application_id", "service", "lms_application_id", "dts_site_number", "transmitter_site", "CONTOUR_POLYGON"]);

// Special field transformations to apply when storing files into database.
define('FCCDATA_FIELD_TRANSFORM', ["transmitter_site" => "json"]);

// Whether to ignore the "Duplicate key exists" error when inserting records from the FM Query.
// Several lines have duplicate Application IDs.
define('FCCDATA_IGNORE_DUPLICATES', true);


define('QUERY_MAX_POINTS', 300);
define('QUERY_MAX_CONTOURS', 50);


// Restore error reporting.
error_reporting($current_error_reporting);
?>