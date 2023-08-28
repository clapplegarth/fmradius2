<?php
header('Content-Type: text/plain');
include("config.php"); // contains file paths, lists of columns



// Loads a $input_line of delimited fields, and zips them with the $schema.
// $limit is used for the case of the Service Contour file, which should be parsed on its own.
function load_fields(string $input_line, string $delimiter, array $schema, int $limit=PHP_INT_MAX)
{
	$in_fields = explode($delimiter, $input_line, $limit);
	$out_fields = [];
	for($i = 0; $i < count($schema); $i++)
	{
		$out_fields[$schema[$i]] = trim($in_fields[$i]);
	}
	return $out_fields;
}

// Converts a comma separated input string into an array of two numbers.
function latlon_from_string(string $in)
{
	$pair = explode(',', $in, 2);
	// print_r($pair);
	if (count($pair) != 2)
	{
		flecho("Error parsing coordinates - not a pair: '" . $in);
	}
	try {
		return [trim($pair[0]), trim($pair[1])]; 
	}
	catch (Exception $e)
	{
		flecho("Error parsing coordinates: " + $e->getMessage());
	}
}

// Converts a comma separated input string into a JSON-formatted array.
function latlon_to_json(string $in, bool $reverse = false)
{
	$a = latlon_from_string($in);
	return "[" . $a[$reverse ? 1 : 0] . "," . $a[$reverse ? 0 : 1] . "]";
}

// Reversed version of latlon_to_json
function lonlat_to_json(string $in, bool $reverse = false)
{
	return latlon_to_json($in, !$reverse);
}

// Construct and execute query for the "fmq.txt" data, which creates rows.
function fcc_fmq_query(PDO $dbconn, array $fields) : string|null
{
	$columns = ["application_id", "service_type", "callsign", "frequency", "lms_application_id", "city", "state", "country"];
	
	// frequency: if exists, cut off the MHz label
	if(array_key_exists('frequency', $fields))
	{
		$fields['frequency'] = explode(" ", trim($fields['frequency']))[0];
	}
	
	// column check
	$column_values = [];
	foreach($columns as $column)
	{
		// 1. ensure the value is present in the input field values
		if (!array_key_exists($column, $fields))
		{
			flecho("Skipping row: No ". $column . " field!");
			return false;
		}
		// 2. build the list of values to insert during the query
		$column_values[":".$column] = $fields[$column];
	}


	// insert the row!
	$stmt = $dbconn->prepare(
		"INSERT INTO `".DB_DBNAME."`.`imported` (`" . implode("`, `", $columns) . "`)" .
		"VALUES (:" . implode(", :", $columns) . ");"
	);
		
	try {
		$stmt->execute($column_values);
		return $stmt->errorInfo()[2];
	} catch (Exception $e) {
		$e_message = $e->getMessage();
		$app_id = $fields['application_id'] ?? "(unknown)";
		
		// In the case of a duplicate, very short message (it's expected)
		if(str_contains($e_message, 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'))
		{

			$err = "Duplicate ID #" . $app_id . ": skipped.";
			// Break out and skip the echo if IGNORE DUPLICATES is on
			if (FCCDATA_IGNORE_DUPLICATES)
				return $err;
		}
		else
		{
			$err = "Error importing ID #" . $app_id  . ": " . $e_message;
		}
		
		// Echo AND return the above error message
		flecho($err);
		return $err;
	}
	
}


// Prepares and executes the query for the "FM_service_contour_current.txt" data.
// This UPDATEs the rows that were created from fcc_fmq_query() with the geospatial contour data.
function fcc_contour_query(PDO $dbconn, array $fields, bool $debug = false) : string|null
{
	$columns = ["transmitter_site_lat", "transmitter_site_lon", "service_contour", "application_id"];
	
	//Per-field parsing:
	// transmitter_site: parse to transmitter_site_lat and transmitter_site_lon
	if (array_key_exists('transmitter_site', $fields))
	{
		$ts_latlon = latlon_from_string($fields['transmitter_site']);
		$fields['transmitter_site_lat'] = $ts_latlon[0];
		$fields['transmitter_site_lon'] = $ts_latlon[1];
	}	
	
	// service_contour: service_contour_fields contains 360 unparsed lat,lon pairs:
	//    "lat ,lon|lat, lon|...|^|"
	$service_contour_fields = explode('|', $fields['CONTOUR_POLYGON']);
	
	// eliminate last two items (each row ends in: "|^|")
	array_pop($service_contour_fields); // pop ""
	array_pop($service_contour_fields); // pop "^"

	// if ($debug)
	// {
	// 	echo "service_contour_fields:\n";
	// 	print_r($service_contour_fields);
	// }
	
	// Parse into a JSON-format nested array:
	//    "[[lat, lon], [lat, lon], ...]"
	$fields['service_contour'] = '[' . implode(", ", array_map('lonlat_to_json', $service_contour_fields)) . ']';
		
	// Ensure all fields are present
	foreach($columns as $column)
	{
		if (!array_key_exists($column, $fields))
		{
			return "Skipping row: No ". $column . " field!\n";
		}
		$query_values[":".$column] = $fields[$column];
	}

	// Update DB row where the application_id matches.
	$stmt = $dbconn->prepare(
		"UPDATE `".DB_DBNAME."`.`imported` SET
			`transmitter_site_lat` = :transmitter_site_lat,
			`transmitter_site_lon` = :transmitter_site_lon,
			`service_contour` = :service_contour
		WHERE `application_id` = :application_id;"
	);
	$stmt->execute($query_values);
	return $stmt->errorInfo()[2]; // Error message text
}

function flecho($str, $end="\n")
{
	echo($str . $end);
	ob_flush();
	flush();
}


// Begin main execution
flecho("Opening database " . DB_DBNAME . "... ");


// Check environment
$dbconn = new PDO(DB_DRIVER . ":host=" . DB_HOST . ";dbname=" . DB_DBNAME, DB_USERNAME, DB_PASSWORD);

// Protect against unnecessary recreation of the table
$stmt = $dbconn->query("SELECT create_time FROM information_schema.tables WHERE table_schema = '".DB_DBNAME."' AND table_name = 'fcc' LIMIT 1;");
if ($stmt->rowCount() > 0)
{
	die("The database 'fcc' already exists.  Please drop the table and run this script again.\n\n");
}


flecho("done.\nCreating temporary table... ");

// Initialize temporary table
$q = "CREATE TEMPORARY TABLE `fmradius`.`imported` (
	`application_id` INT NOT NULL PRIMARY KEY,
	`service_type` VARCHAR(8) NOT NULL DEFAULT 'FM',
	`callsign` VARCHAR(16) NOT NULL,
	`frequency` DECIMAL(4,1) NOT NULL,
	`lms_application_id` VARCHAR(40) NOT NULL,
	`city` TEXT NOT NULL,
	`state` VARCHAR(8) NOT NULL,
	`country` VARCHAR(32) NOT NULL,
	`transmitter_site_lat` FLOAT,
	`transmitter_site_lon` FLOAT,
	`service_contour` TEXT);";

$dbconn->query($q);

echo("done.\nInserting rows from FM Query Results file... ");


if ($fo = fopen(FCCDATA_DIR . FCCDATA_FMQ_FILE, 'r'))
{
	$i = 0;
	$duplicates = 0;
	while (($line = fgets($fo)) !== false)
	{
		$fields = load_fields($line, "|", FCCDATA_FMQ_SCHEMA);
		$res = fcc_fmq_query($dbconn, $fields);
		
		// Track duplicates
		if (str_starts_with($res, 'Duplicate ID'))
			$duplicates++;
		
		// Print every so often
		$i++;
		if ($i % 1000 == 0)
			flecho($i . "... ");
		
	}
	fclose($fo);
}
else
{
	die("Error opening file: " . FCCDATA_DIR . FCCDATA_FMQ_FILE);
}

// Get count of created rows
try {
	$stmt = $dbconn->query("SELECT COUNT(*) FROM `".DB_DBNAME."`.`imported`;");
	flecho("done.  Ignored " . $duplicates . " duplicates with the same Application ID.\n" . $stmt->fetch()['COUNT(*)'] . " rows loaded from FM Query Results.\n");
} catch (Exception $e) {
	flecho("Issue checking temporary table ".DB_DBNAME.".imported after inserting FM Query Results: " . $e->getMessage() . "\n");
}

flecho("Loading service contour data... ");

if ($fo = fopen(FCCDATA_DIR . FCCDATA_CONTOURS_FILE, 'r'))
{
	// Skip header line.
	$line = fgets($fo);
	
	$i=0;
	while (($line = fgets($fo)) !== false)
	{
		$fields = load_fields($line, "|", FCCDATA_CONTOUR_SCHEMA, 6);
		fcc_contour_query($dbconn, $fields, ($i % 1000 == 1));
		$i++;
		if ($i % 1000 == 0)
			flecho($i . "... ");
	}
	fclose($fo);
}
else
{
	die("Error opening file: " . FCCDATA_DIR . FCCDATA_CONTOURS_FILE);
}

// Get count of created rows
try {
	$stmt = $dbconn->query("SELECT COUNT(*) FROM `".DB_DBNAME."`.`imported` WHERE `service_contour` IS NOT NULL;");
	flecho($stmt->fetch()['COUNT(*)'] . " rows updated with FM Service Contours.\n");
} catch (Exception $e) {
	flecho("Issue checking temporary table ".DB_DBNAME.".imported after inserting FM Service Contours: " . $e->getMessage() . "\n");
}



// Load into database
$dbconn->query("DROP TABLE IF EXISTS `".DB_DBNAME."`.`fcc`; CREATE TABLE `".DB_DBNAME."`.`fcc` AS SELECT * FROM `".DB_DBNAME."`.`imported`;");


// Clean up
$dbconn = null;

flecho("Database " . DB_DBNAME . " closed.\n");

?>